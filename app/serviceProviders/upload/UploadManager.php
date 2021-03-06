<?php namespace uk\co\la1tv\website\serviceProviders\upload;

use Response;
use Redirect;
use Session;
use Config;
use DB;
use FormHelpers;
use Exception;
use Csrf;
use EloquentHelpers;
use FileHelpers;
use Auth;
use Queue;
use Cache;
use uk\co\la1tv\website\models\UploadPoint;
use uk\co\la1tv\website\models\File;
use uk\co\la1tv\website\models\OldFileId;

class UploadManager {

	private static $maxFileLength = 50; // length of varchar in db
	
	private $processCalled = false;
	private $responseData = array();
	
	// process the file that has been uploaded
	// Returns true if succeeds or false otherwise
	// The file may be a chunk of the complete file in which case this just puts it to one side/builds the file with the new chunks.
	// When the last chunk arrives it will then create the db etc.
	public function process($allowedIds=null) {
		
		if ($this->processCalled) {
			throw(new Exception("'process' can only be called once."));
		}
		$this->processCalled = true;
		
		$this->responseData = array("success"=> false);
		$success = false;
		
		$info = $this->buildFile();
		if (!$info['success']) {
			$success = false;
			$this->responseData['success'] = false;
			$this->responseData['wasChunk'] = true;
		}
		else if (is_null($info['info'])) {
			$success = true;
			$this->responseData['success'] = true;
			$this->responseData['wasChunk'] = true;
		}
		else {
			$fileInfo = $info['info'];
			$uploadPointId = FormHelpers::getValue("upload_point_id");
			
			if (Csrf::hasValidToken() && !is_null($uploadPointId) && (is_null($allowedIds) || in_array($uploadPointId, $allowedIds, true))) {
				
				$uploadPointId = intval($uploadPointId, 10);
				$uploadPoint = UploadPoint::with("fileType", "fileType.extensions")->find($uploadPointId);
				
				if (!is_null($uploadPoint) && strlen($fileInfo['name']) <= self::$maxFileLength) {
					
					$fileLocation = $fileInfo['path'];
					$fileName = $fileInfo['name'];
					$fileSize = FileHelpers::filesize64($fileLocation);
					
					$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
					$extensions = array();
					$extensionModels = $uploadPoint->fileType->extensions;
					if (!is_null($extensionModels)) {
						foreach($extensionModels as $a) {
							$extensions[] = $a->extension;
						}
					}
					if (in_array($extension, $extensions) && $fileSize != FALSE && $fileSize > 0) {

						$fileDb = DB::transaction(function() use (&$fileName, &$fileSize, &$uploadPoint) {
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
								return $fileDb;
							}
							return null;
						});				

						$success = !is_null($fileDb);

						if ($success) {
							// queue the job which will move the file to the file server and queue the processing
							Queue::push("uk\co\la1tv\website\jobs\TransferUploadJob", array(
								"fileId"	=> intval($fileDb->id),
								"filePath"	=> $fileLocation
							), "uploadTransfer");

							$this->responseData['success'] = true;
							$this->responseData['id'] = intval($fileDb->id);
							$this->responseData['fileName'] = $fileName;
							$this->responseData['fileSize'] = $fileSize;
							$this->responseData['processInfo'] = $fileDb->getProcessInfo();
						}
					}
				}
			}
		}
		return $success;
	}
	
	// buildFile will append the current file chunk to the stored chunks.
	// if this is the last chunk and there's now a complete file it returns the info about the completed file, otherwise the info key is null, meaning there's more chunks left to come in
	// the return value an array of form array("success", "info"=>array("name", "path")) where "name" is the files original name and "path" is the path to the built file. success is false if there was an error with the current chunk.
	// it names the files as [session_id]-[fileid]-[original name]. this means when a users session expires any incomplete chunks can be removed easily
	private function buildFile() {
		$returnVal = array("success" => false, "info" => null);
		
		// http://www.plupload.com/docs/Chunking
		if (!empty($_FILES) && is_uploaded_file($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === 0) {
			$chunk = isset($_POST["chunk"]) ? intval($_POST["chunk"]) : 0;
			$chunks = isset($_POST["chunks"]) ? intval($_POST["chunks"]) : 0;

			if (isset($_POST['id']) && ctype_digit($_POST['id'])) {
				$fileId = intval($_POST['id']);
				$actualFileName = isset($_POST['name']) ? $_POST["name"] : $_FILES["file"]["name"];
				$fileName = Session::getId()."-".$fileId."-".$actualFileName;
				$filePath = Config::get("custom.file_chunks_location") . DIRECTORY_SEPARATOR . $fileName;
				
				if ($chunk > 0 && !file_exists($filePath.".part")) {
					// should be appending to file that's already been started
					// It might be a complete file now, but the webpage is reuploading the last chunk
					// because something failed after this point (e.g the copy to the filestore timing out)
					throw(new Exception("Source file for chunk to be appended to is missing."));
				}

				// Open temp file
				$out = @fopen($filePath.".part", $chunk === 0 ? "wb" : "ab");
				if ($out) {
					// Read binary input stream and append it to temp file
					$in = @fopen($_FILES['file']['tmp_name'], "rb");
					if ($in) {
						while ($buff = fread($in, 4096)) {
							fwrite($out, $buff);
						}
						@fclose($in);
						@fclose($out);
						$returnVal['success'] = true;
						// Check if the complete file has now been uploaded
						if ($chunks === 0 || $chunk === $chunks - 1) {
							// Strip the temp .part suffix off
							rename($filePath.".part", $filePath);
							$returnVal['info'] = array(
								"name"	=> $actualFileName,
								"path"	=> $filePath
							);
						}
					}
					else {
						@fclose($in);
						@fclose($out);
					}
				}
				else {
					@fclose($out);
				}
				@unlink($_FILES['file']['tmp_name']);
			}
		}	
		return $returnVal;
	}
	
	// get the Laravel response (json) object to be returned to the user
	public function getResponse() {
		if (!$this->processCalled) {
			throw(new Exception("'process' must have been called first."));
		}
		return Response::json($this->responseData);
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
			$oldIds = array();
			// if the old file has old file ids that used to point to it then save them so they can be copied across
			if (!is_null($fileToReplace)) {
				$oldIds = $fileToReplace->oldFileIds()->get()->map(function($a) {
					return intval($a->old_file_id);
				});
				$oldIds[] = intval($fileToReplace->id);
			}
			
			// this must happen before new file is saved (after the old ids that pointed to this file have been retrieved)
			// so that don't end up with duplicate old file ids
			if (!is_null($fileToReplace)) {
				self::delete($fileToReplace);
			}

			if (!is_null($file)) {
				foreach($oldIds as $a) {
					$file->oldFileIds()->save(new OldFileId(array(
						"old_file_id"	=> $a
					)));
				}
				
				if ($file->save() === false) {
					throw(new Exception("Error saving file model."));
				}
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
				$a->oldFileIds()->delete();
				$a->markReadyForDelete();
				$a->save();
			}
		}
	}
	
	// returns the File object for a file if the security checks pass.
	// returns the File model or null
	public static function getFile($fileId) {
		
		$user = Auth::getUser();
		$cacheKeyUserId = !is_null($user) ? intval($user->id) : -1;
		$cacheKey = "uploadManager.getFile.accessAllowed.".$cacheKeyUserId.".".$fileId;

		$accessAllowed = Cache::remember($cacheKey, 120, function() use(&$fileId, &$user) {

			// the file must be a render (ie have a source file) file to be valid. Then the security checks are performed on the source file.
			$relationsToLoad = array(
				"sourceFile",
				"sourceFile.mediaItemWithBanner",
				"sourceFile.mediaItemWithBannerFill",
				"sourceFile.mediaItemWithCover",
				"sourceFile.mediaItemWithCoverArt",
				"sourceFile.playlistWithBanner",
				"sourceFile.playlistWithBannerFill",
				"sourceFile.playlistWithCover",
				"sourceFile.liveStreamWithCoverArt",
				"sourceFile.mediaItemVideoWithFile.mediaItem",
				"sourceFile.videoFileDashWithMediaPresentationDescription",
				"sourceFile.videoFileDashWithAudioChannel",
				"sourceFile.videoFileDashWithVideoChannel"
			);

			$requestedFile = File::with($relationsToLoad)->finishedProcessing()->find($fileId);
			if (is_null($requestedFile)) {
				return null;
			}
			
			$fileType = $requestedFile->file_type;
			$fileTypeId = intval($fileType->id);
			
			$hasMediaItemsPermission = false;
			$hasPlaylistsPermission = false;
			$hasLiveStreamsPermission = false;
			$hasMediaItemsEditPermission = false;
			$hasPlaylistsEditPermission = false;
			$hasLiveStreamsEditPermission = false;
			if (!is_null($user)) {
				$hasMediaItemsPermission = $user->hasPermission(Config::get("permissions.mediaItems"), 0);
				$hasPlaylistsPermission = $user->hasPermission(Config::get("permissions.playlists"), 0);
				$hasLiveStreamsPermission = $user->hasPermission(Config::get("permissions.liveStreams"), 0);
				$hasMediaItemsEditPermission = $user->hasPermission(Config::get("permissions.mediaItems"), 1);
				$hasPlaylistsEditPermission = $user->hasPermission(Config::get("permissions.playlists"), 1);
				$hasLiveStreamsEditPermission = $user->hasPermission(Config::get("permissions.liveStreams"), 1);
			}


			$accessAllowed = false;

			$sourceFile = $requestedFile->sourceFile;
			if (is_null($sourceFile)) {
				// this is a source file
				// if the user is logged into the cms and has the relevent edit permission
				// meaning they would have been able to upload the source file, then allow
				// them to download it.

				// side banner source images
				if ($fileTypeId === 1 && $hasMediaItemsEditPermission && !is_null($requestedFile->mediaItemWithBanner)) {
					$accessAllowed = true;
				}
				else if ($fileTypeId === 1 && $hasPlaylistsEditPermission && !is_null($requestedFile->playlistWithBanner)) {
					$accessAllowed = true;
				}
				// cover source images
				else if ($fileTypeId === 2 && $hasMediaItemsEditPermission && !is_null($requestedFile->mediaItemWithCover)) {
					$accessAllowed = true;
				}
				else if ($fileTypeId === 2 && $hasPlaylistsEditPermission && !is_null($requestedFile->playlistWithCover)) {
					$accessAllowed = true;
				}
				// video upload
				else if ($fileTypeId === 3 && $hasMediaItemsEditPermission && !is_null($requestedFile->mediaItemVideoWithFile)) {
					$accessAllowed = true;
				}
				//cover art source images
				else if ($fileTypeId === 4 && $hasMediaItemsEditPermission && !is_null($requestedFile->mediaItemWithCoverArt)) {
					$accessAllowed = true;
				}
				else if ($fileTypeId === 4 && $hasPlaylistsEditPermission && !is_null($requestedFile->playlistWithCoverArt)) {
					$accessAllowed = true;
				}
				else if ($fileTypeId === 4 && $hasLiveStreamsEditPermission && !is_null($requestedFile->liveStreamWithCoverArt)) {
					$accessAllowed = true;
				}
				// side banner fill images
				else if ($fileTypeId === 10 && $hasMediaItemsEditPermission && !is_null($requestedFile->mediaItemWithBannerFill)) {
					$accessAllowed = true;
				}
				else if ($fileTypeId === 10 && $hasPlaylistsEditPermission && !is_null($requestedFile->playlistWithBannerFill)) {
					$accessAllowed = true;
				}
			}
			else {
			
				// see if the file should be accessible
				if ($fileTypeId === 5 && !is_null($sourceFile->mediaItemWithBanner)) {
					if ($sourceFile->mediaItemWithBanner->getIsAccessible()) {
						$accessAllowed = true;
					}
				}
				else if ($fileTypeId === 11 && !is_null($sourceFile->mediaItemWithBannerFill)) {
					if ($sourceFile->mediaItemWithBannerFill->getIsAccessible()) {
						$accessAllowed = true;
					}
				}
				else if ($fileTypeId === 6 && !is_null($sourceFile->mediaItemWithCover)) {
					if ($sourceFile->mediaItemWithCover->getIsAccessible()) {
						$accessAllowed = true;
					}
				}
				else if ($fileTypeId === 8 && !is_null($sourceFile->mediaItemWithCoverArt)) {
					if ($sourceFile->mediaItemWithCoverArt->getIsAccessible()) {
						$accessAllowed = true;
					}
				}
				// file type 9 = video scrub thumbnail,
				// 12 = dash media presentation description files
				// 13 = dash segment file
				// 15 = hls playlist file
				// 16 = hls segment file
				// these should only be accessible if the video itself is
				else if (($fileTypeId === 7 || $fileTypeId === 9 || $fileTypeId === 12 || $fileTypeId === 13 || $fileTypeId === 15 || $fileTypeId === 16) && !is_null($sourceFile->mediaItemVideoWithFile)) {
					if ($sourceFile->mediaItemVideoWithFile->mediaItem->getIsAccessible() && ($sourceFile->mediaItemVideoWithFile->getIsLive() || $hasMediaItemsPermission)) {
						$accessAllowed = true;
					}
				}
				else if ($fileTypeId === 5 && !is_null($sourceFile->playlistWithBanner)) {
					if ($sourceFile->playlistWithBanner->getIsAccessible() && ($sourceFile->playlistWithBanner->getIsAccessibleToPublic() || $hasPlaylistsPermission)) {
						$accessAllowed = true;
					}
				}
				else if ($fileTypeId === 11 && !is_null($sourceFile->playlistWithBannerFill)) {
					if ($sourceFile->playlistWithBannerFill->getIsAccessible() && ($sourceFile->playlistWithBannerFill->getIsAccessibleToPublic() || $hasPlaylistsPermission)) {
						$accessAllowed = true;
					}
				}
				else if ($fileTypeId === 6 && !is_null($sourceFile->playlistWithCover)) {
					if ($sourceFile->playlistWithCover->getIsAccessible() && ($sourceFile->playlistWithCover->getIsAccessibleToPublic() || $hasPlaylistsPermission)) {
						$accessAllowed = true;
					}
				}
				else if ($fileTypeId === 8 && !is_null($sourceFile->playlistWithCoverArt)) {
					if ($sourceFile->playlistWithCoverArt->getIsAccessible() && ($sourceFile->playlistWithCoverArt->getIsAccessibleToPublic() || $hasPlaylistsPermission)) {
						$accessAllowed = true;
					}
				}
				else if ($fileTypeId === 8 && !is_null($sourceFile->liveStreamWithCoverArt)) {
					if ($sourceFile->liveStreamWithCoverArt->getShowAsLiveStream()) {
						$accessAllowed = true;
					}
				}
			}

			// if access is not allowed return null, as this will not be cached.
			return $accessAllowed ? true : null;
		}, true) === true; // to concert to boolean (because can be null, see above)
		
		return $accessAllowed ? File::find($fileId) : null;
	}
	
	// helper that returns true if the current user should have access to this file
	public static function hasAccessToFile($fileId) {
		return !is_null(self::getFile($fileId));
	}
	
	// returns the file laravel response that should be returned to the user.
	// this will either be the file (with cache header to cache for a year), a redirect if the file has been updated, or a 404
	public static function getFileResponse($fileId) {
		$file = self::getFile($fileId);
		if (is_null($file)) {
			// see if the file used to exist, and if it did then return a redirect to the new version
			$oldFileIdModel = OldFileId::where("old_file_id", $fileId)->first();
			$newFileUri = !is_null($oldFileIdModel) ? $oldFileIdModel->newFile->getUri() : null;
			if (!is_null($newFileUri)) {
				// file has moved
				// return permanent redirect
				return Redirect::away($newFileUri, 301);
			}
			else {
				// file doesn't exist (or is not accessible for some reason)
				// return 404 response
				return Response::make("", 404);
			}
		}

		$headers = array();
		$mimeType = $file->fileType->mime_type;
		if (!is_null($mimeType)) {
			// explicitly set the mime type
			// if not set it 'should' be detected automatically
			$headers["Content-Type"] = $mimeType;
		}

		$filename = null;
		if (is_null($file->filename)) {
			$filename = sha1("la1tv-".$file->id);
		}
		else {
			$filename = $file->filename;
		}

		// return response with cache header set for client to cache for a year
		return Response::download(Config::get("custom.files_location") . DIRECTORY_SEPARATOR . $file->id, $filename, $headers)->setContentDisposition("inline", $filename)->setClientTtl(31556926)->setTtl(31556926)->setEtag($file->id);
	}
}