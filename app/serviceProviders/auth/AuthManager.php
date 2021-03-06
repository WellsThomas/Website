<?php namespace uk\co\la1tv\website\serviceProviders\auth;

use uk\co\la1tv\website\serviceProviders\auth\exceptions\NoUserLoggedInException;
use uk\co\la1tv\website\serviceProviders\auth\exceptions\ErrorLoggingOutException;
use uk\co\la1tv\website\serviceProviders\auth\exceptions\UserAlreadyLoggedInException;

use uk\co\la1tv\website\models\User;
use uk\co\la1tv\website\models\UserSession;
use Hash;
use Config;
use Session;
use App;
use Carbon;
use Redirect;
use Cookie;
use uk\co\la1tv\website\serviceProviders\cosign\Cosign;

class AuthManager {
	
	private $user = null; // contains the user model after is has been requested if a user is logged in
	private $cosignUser = null; // contains the cosign user name after it has been requested
	private $requestInterval = null;
	private $cosign = null;
	
	public function __construct() {
		$this->requestInterval = Config::get("auth.attemptInterval");
		$this->cosign = new Cosign(Config::get("auth.cosignServiceName"), Config::get("auth.cosignFilterDbLocation"));
	}
	
	// returns the user name of the logged in cosign user or null if no cosign user logged in
	public function getCosignUser() {
		
		if ($this->cosignUser) {
			//return cached version
			return $this->cosignUser;
		}
		else if (App::environment() === 'production') {
			// couldn't use $_SERVER variables (remote_realm and remote_user) because of issues of them getting renamed with fastcgi, and also remote_user getting overwritten with the username used for basic auth, when apache basic auth is enabled
			// http://webapps.itcs.umich.edu/cosign/index.php/Cosign_Wiki:CosignFilterSpec
			// so build CosignServiceProvider to talk to the db directly and use that instead
			
			if (!is_null($this->cosign->getTime()) && $this->cosign->getTime() >= time()-Config::get("auth.cosignCookieDuration")) {
				// cookie hasn't expired
				if (!is_null($this->cosign->getUsername())) {
					$this->cosignUser = strtolower($this->cosign->getUsername());
				}
			}
		}
		return $this->cosignUser;
	}

	// gets the user model corresponding to the logged in user
	// returns null if there is not a registered user logged in
	public function getUser() {
		// if the user has been requested before returned cached version
		if (!is_null($this->user)) {
			return $this->user;
		}
		
		// check for a user that has this session_id in their list of current sessions
		$this->user = User::with("userSessions")->whereHas("userSessions", function($q) {
			$q->where("session_id", Session::getId());
		})->first();
		return $this->user;
	}
	
	// helper that returns true if user logged in
	public function isLoggedIn() {
		return !is_null($this->getUser());
	}
	
	public function loggedInOr403() {
		if (!$this->isLoggedIn()) {
			App::abort(403); // forbidden
		}
	}
	
	public function isLoggedIntoCosign() {
		return !is_null($this->getCosignUser());
	}
	
	public function isCurrentUserLoggedIntoCosign() {
		if (is_null($this->getUser())) {
			throw(new NoUserLoggedInException());
		}
		if (is_null($this->getUser()->cosign_user)) {
			return false;
		}
		return $this->getUser()->cosign_user === $this->getCosignUser();
	}
	
	// returns true if the current cosign user has an account on the system.
	public function currentCosignUserHasAccount() {
		if (is_null($this->getCosignUser())) {
			throw(new NoUserLoggedInException());
		}
		return User::where("cosign_user", $this->getCosignUser())->count() !== 0;
	}
	
	// returns true if the last user that was logged in logged in with cosign, and are still logged into cosign
	public function wasCosignUserLoggedIn() {
		if (is_null($this->getCosignUser())) {
			return false;
		}
		return Session::get("lastCosignUserLoggedIn", null) === $this->getCosignUser();
	}
	
	// return login URL for redirecting the user to cosign
	public function getLoginUrl($redirectLocation="") {
		return "https://weblogin.lancs.ac.uk/?".Config::get("auth.cosignServiceName")."&http".(Config::get("ssl.enabled")?"s":"")."://www.la1tv.co.uk/".$redirectLocation;
	}
	
	// returns
	// 0: user is in normal active state
	// 1: account disabled by admin. Shouldn't be allowed to use system.
	public function getUserState() {
		if (is_null($this->getUser())) {
			// error
			throw(new NoUserLoggedInException());
		}
		if ($this->getUser()->disabled) {
			return 1;
		}
		else {
			return 0;
		}
	}
	
	// attempts to login the user with cosign
	// returns true if successful
	public function loginWithCosign() {
		if (!is_null($this->getUser())) {
			throw(new UserAlreadyLoggedInException());
		}
		
		// try and log in user from cosign information
		if (App::environment() === 'production' && $this->getCosignUser()) {
			// attempt to authenticate with cosign user
			$a = User::where("cosign_user", $this->getCosignUser())->first();
			if (!is_null($a)) {
				if ($this->authenticateUser($a)) {
					$this->user = $a;
				}
			}
		}
		return !is_null($this->getUser());
	}

	// attempts to login with a username and password
	// returns true if successful.
	public function login($username, $password) {
		if (!is_null($this->getUser())) {
			throw(new UserAlreadyLoggedInException());
		}
		
		if (empty($username) || $password === "") {
			return false;
		}
		
		$username = strtolower($username);
		
		// find user model and if valid set to $user
		$user = User::where("username", $username)->first();
		if (is_null($user)) {
			$this->doSleep();
			return false;
		}
		
		$lastLoginAttempt = $user->last_login_attempt;
		// update last login attempt
		$now = Carbon::now();
		$user->last_login_attempt = $now;
		if (!$user->save()) {
			$this->doSleep($lastLoginAttempt);
			return false;
		}
		
		$this->doSleep($lastLoginAttempt);
		$user = User::where("username", $username)->first();
		if (is_null($user) || $user->last_login_attempt->timestamp !== $now->timestamp) {
			// user deleted or has attempted to log in again whilst this was sleeping. Don't authenticate this attempt
			return false;
		}
		
		if (!Hash::check($password, $user->password_hash)) {
			return false;
		}
		
		if (Hash::needsRehash($user->password_hash)) {
			// happens if now needs to be converted to a more secure hash (ie more hash cycles/different hash algorithm altogether etc)
			$user->password_hash = Hash::make($password);
			if (!$user->save()) {
				return false;
			}
		}
		if (!$this->authenticateUser($user)) {
			return false;
		}
		$this->user = $user;
		return !is_null($this->getUser());
	}
	
	private function authenticateUser(User $user) {
		// add this session id to the users list of sessions
		$userSession = new UserSession(array(
			"session_id"	=> Session::getId()
		));
		$user->userSessions()->save($userSession);
		$this->updateLastCosignUser();
		return true;
	}
	
	// log the user out of the site. This does not log the user out of cosign.
	// returns true if successfully logged out
	public function logout() {
		if (is_null($this->getUser())) {
			// already logged out
			return false;
		}
		$this->user->userSessions()->where("session_id", Session::getId())->delete();
		$this->user = null;
		return true;
	}
	
	// returns the redirect route that should then be returned from the controller.
	public function logoutCosign($redirectLocation="") {
		
		$redirectUrl = url('/').$redirectLocation;
		
		if (is_null($this->getCosignUser())) {
			return Redirect::to($redirectUrl);
		}
		
		// http://www.lancaster.ac.uk/iss/tsg/cosign/using_php.html
		$logoutUrl="https://weblogin.lancs.ac.uk/logout";
		return Redirect::to($logoutUrl."?".$redirectUrl)->withCookie(Cookie::forget(Config::get("auth.cosignServiceName")));
	}
	
	// only allow a request once every $requestInterval seconds (with a bit of randomness) seconds for a particular user.
	// pass in the time the last request was made.
	// if null is passed in then the sleep will occur for a second
	// the way this works means someone could determine if a user name is correct by measuring the response times, but if they do guess a correct user name brute forcing the password should be infeasible
	private function doSleep($lastAttempt=null) {
		$randAmount = rand(0, 100) * 10000;
		if (is_null($lastAttempt)) {
			usleep(1000000 + $randAmount);
		}
		else {
			usleep(max(($this->requestInterval - $lastAttempt->diffInSeconds()) * 1000000, 1000000) + $randAmount);
		}
	}
	
	private function updateLastCosignUser() {
		// contains the cosign username of the last cosign user that logged into the system successfully
		Session::put("lastCosignUserLoggedIn",$this->isLoggedIn() ? $this->getCosignUser() : null);
	}

}