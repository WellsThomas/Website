<?php namespace uk\co\la1tv\website\serviceProviders\upload;

use Response;
use Session;
use Config;
use DB;
use FormHelpers;
use Exception;
use Csrf;
use EloquentHelpers;
use uk\co\la1tv\website\models\UploadPoint;
use uk\co\la1tv\website\models\File;
use uk\co\la1tv\website\fileObjs\FileObjBuilder;

class UploadManager {

	private static $maxFileLength = 50; // length of varchar in db
	
	private $processCalled = false;
	private $responseData = array();
	
	// process the file that has been uploaded
	// Returns true if succeeds or false otherwise
	public function process($allowedIds=null) {
		
		if ($this->processCalled) {
			throw(new Exception("'process' can only be called once."));
		}
		$this->processCalled = true;
		
		$this->responseData = array("success"=> false);
		$success = false;
		
		$uploadPointId = FormHelpers::getValue("upload_point_id");
		
		if (Csrf::hasValidToken() && !is_null($uploadPointId) && (is_null($allowedIds) || in_array($uploadPointId, $allowedIds, true))) {
			$uploadPointId = intval($uploadPointId, 10);
			$uploadPoint = UploadPoint::with("fileType", "fileType.extensions")->find($uploadPointId);
			
			if (!is_null($uploadPoint) && isset($_FILES['files']) && count($_FILES['files']['name']) >= 1 && strlen($_FILES['files']['name'][0]) <= self::$maxFileLength && isset($_FILES['files']['tmp_name'][0])) {
				
				$fileLocation = $_FILES['files']['tmp_name'][0];
				$fileName = $_FILES['files']['name'][0];
				$fileSize = filesize($fileLocation);
				
				$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
				$extensions = array();
				$extensionModels = $uploadPoint->fileType->extensions;
				if (!is_null($extensionModels)) {
					foreach($extensionModels as $a) {
						$extensions[] = $a->extension;
					}
				}
				if (in_array($extension, $extensions) && $fileSize != FALSE && $fileSize > 0) {

					try {
						DB::beginTransaction();
						
						// create the file reference in the db
						$fileDb = new File(array(
							"in_use"	=> false,
							"filename"	=> $fileName,
							"size"		=> $fileSize,
							"session_id"	=> Session::getId() // the laravel session id
						));
						$fileDb->fileType()->associate($uploadPoint->fileType);
						$fileDb->uploadPoint()->associate($uploadPoint);
						if ($fileDb->save() !== FALSE) {
							// move the file
							if (move_uploaded_file($fileLocation, Config::get("custom.files_location") . DIRECTORY_SEPARATOR . $fileDb->id)) {				
								
								// commit transaction so file record is committed to database
								DB::commit();
								
								// success
								$success = true;
								$this->responseData['success'] = true;
								$this->responseData['id'] = $fileDb->id;
								$this->responseData['fileName'] = $fileName;
								$this->responseData['fileSize'] = $fileSize;
							}
							else {
								DB::rollback();
							}
						}
						else {
							DB::rollback();
						}
					}
					catch (\Exception $e) {
						DB::rollback();
						throw($e);
					}
				}
			}
		}
		return $success;
	}
	
	// get the Laravel response (json) object to be returned to the user
	public function getResponse() {
		if (!$this->processCalled) {
			throw(new Exception("'process' must have been called first."));
		}
		return Response::json($this->responseData);
	}
	
	// get an array containing information about the last upload
	// returns array or null if there was an error processing
	public function getInfo() {
		if (!$this->processCalled) {
			throw(new Exception("'process' must have been called first."));
		}
		$data = $this->responseData;
		return $data['success'] ? array("fileName"=>$data['fileName'], "fileSize"=>$data['fileSize']) : null;
	}
	
	// register a file as now in use by its id. It assumed that this id is valid. an exception is thrown otherwise
	// if the file has already been registered then an exception is thrown, unless the $fileToReplace is the same file.
	// the first parameter is the upload point id and this is used to check that the file being registered is one that was uploaded at the expected upload point
	// optionally pass in the File object of a file that this will be replacing.
	// returns the File model of the registered file or null if $fileId was null
	// if the $fileId is null then the $fileToReplace will be removed and null will be returned.
	public static function register($uploadPointId, $fileId, File $fileToReplace=null) {
		
		$uploadPoint = UploadPoint::with("fileType", "fileType.extensions")->find($uploadPointId);	
	
		if (is_null($uploadPoint)) {
			throw(new Exception("Invalid upload point."));
		}
		
		if (!is_null($fileToReplace) && !is_null($fileId) && intval($fileToReplace->id, 10) === intval($fileId, 10)) {
			// if we are replacing the file with the same file then nothing to do.
			// just return the model
			return $fileToReplace;
		}
		
		$file = null;
		if (!is_null($fileId)) {
			$fileId = intval($fileId, 10);
			$file = File::with("uploadPoint")->find($fileId);
			if (is_null($file)) {
				throw(new Exception("File model could not be found."));
			}
			else if (is_null($file->uploadPoint)) {
				throw(new Exception("This file doesn't have an upload point. This probably means it was created externally and 'register' should not be used on it."));
			}
			else if ($file->in_use) {
				throw(new Exception("This file has already been registered."));
			}
			else if ($file->uploadPoint->id !== $uploadPoint->id) {
				throw(new Exception("Upload points don't match. This could happen if a file was uploaded at one upload point and now the file with that id is being registered somewhere else."));
			}
		}
		
		if (!is_null($file)) {
			$file->in_use = true; // mark file as being in_use now
		}
		DB::transaction(function() use (&$file, &$fileToReplace) {
			
			if (!is_null($file)) {
				if ($file->save() === false) {
					throw(new Exception("Error saving file model."));
				}
			}
			if (!is_null($fileToReplace)) {
				self::delete($fileToReplace);
			}
		});
		
		return $file;
	}
	
	// mark the files/file past in for deletion
	// will ignore any null models
	public static function delete($files) {
		if (!is_array($files)) {
			$files = array($files);
		}
		foreach($files as $a) {
			if (!is_null($a)) {
				$a->markReadyForDelete();
			}
		}
	}

	// return an information array about the file
	// there may be extra information available for certain file types
	// eager load the 'fileType' relation with the model before this function for best performance
	
	// TODO: work in progress
/*	public static function getInfo(File $file) {
		$info = array(
			"name"	=> $file->filename,
			"size"	=> $file->size
		);
		$type = $file->fileType();
		if ($type->id == 3) { // vod video uploads
			
		}
		else if ($type->id == 4) { // cover art for media
		
		}
		
		return $info;
	}
*/
}