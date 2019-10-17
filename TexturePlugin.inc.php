<?php

/**
 * @file plugins/generic/texture/SubstancePlugin.inc.php
 *
 * Copyright (c) 2003-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class SubstancePlugin
 * @ingroup plugins_generic_texture
 *
 * @brief Substance JATS editor plugin
 *
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class TexturePlugin extends GenericPlugin {
	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.texture.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.texture.description');
	}


	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		if (parent::register($category, $path, $mainContextId)) {
			if ($this->getEnabled()) {
				// Register callbacks.
				HookRegistry::register('LoadHandler', array($this, 'callbackLoadHandler'));
				HookRegistry::register('TemplateManager::fetch', array($this, 'templateFetchCallback'));
				HookRegistry::register('submissionfilesuploadform::execute', array($this, 'processUpload'));

				$this->_registerTemplateResource();
			}
			return true;
		}
		return false;
	}

	/**
	 * Get texture editor URL
	 * @param $request PKPRequest
	 * @return string
	 */
	function getTextureUrl($request) {
		return $this->getPluginUrl($request) . '/texture';
	}

	/**
	 * Get plugin URL
	 * @param $request PKPRequest
	 * @return string
	 */
	function getPluginUrl($request) {
		return $request->getBaseUrl() . '/' . $this->getPluginPath();
	}

	/**
	 * @see PKPPageRouter::route()
	 */
	public function callbackLoadHandler($hookName, $args) {
		$page = $args[0];
		$op = $args[1];

		switch ("$page/$op") {
			case 'texture/editor':
			case 'texture/json':
			case 'texture/save':
			case 'texture/media':
				define('HANDLER_CLASS', 'TextureHandler');
				define('TEXTURE_PLUGIN_NAME', $this->getName());
				$args[2] = $this->getPluginPath() . '/' . 'TextureHandler.inc.php';
				break;
		}

		return false;
	}

	/**
	 * Adds additional links to submission files grid row
	 * @param $hookName string The name of the invoked hook
	 * @param $args array Hook parameters
	 */
	public function templateFetchCallback($hookName, $params) {
		$request = $this->getRequest();
		$router = $request->getRouter();
		$dispatcher = $router->getDispatcher();
		$journal = $request->getJournal();
		$journalId = $journal->getId();

		$templateMgr = $params[0];
		$resourceName = $params[1];
		if ($resourceName == 'controllers/grid/gridRow.tpl') {
			$row = $templateMgr->getTemplateVars('row');
			$data = $row->getData();
			if (is_array($data) && (isset($data['submissionFile']))) {
				$submissionFile = $data['submissionFile'];
				$fileExtension = strtolower($submissionFile->getExtension());

				// get stage ID
				$stage = $submissionFile->getFileStage();
				$stageId = (int) $request->getUserVar('stageId');

				if (strtolower($fileExtension) == 'xml') {
					import('lib.pkp.classes.linkAction.request.OpenWindowAction');
					$row->addAction(new LinkAction(
						'editor',
						new OpenWindowAction(
							$dispatcher->url($request, ROUTE_PAGE, null, 'texture', 'editor', null,
								array(
									'submissionId' => $submissionFile->getSubmissionId(),
									'fileId' => $submissionFile->getFileId(),
									'stageId' => $stageId
								)
							)
						),
						__('plugins.generic.texture.links.editWithTexture'),
						null
					));
				}
			}
		}
	}

	/**
	 * creates dependent file
	 * @param $genreId int Genre of the new dependent file
	 * @param $mediaData string Dependent media file contents
	 * @param $submission Submission Submission to which to attach the dependent file
	 * @param $submissionFile SubmissionFile Submission file to which to attach the dependent file
	 * @param $userid int Submitting user
	 * @return SubmissionArtworkFile
	 */
	public function createDependentFile($genreId, $mediaData, $submission, $submissionFile, $userid) {
		$tmpfname = tempnam(sys_get_temp_dir(), 'texture');
		file_put_contents($tmpfname, $mediaData);

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$newMediaFile = $submissionFileDao->newDataObjectByGenreId($genreId);
		$newMediaFile->setSubmissionId($submission->getId());
		$newMediaFile->setSubmissionLocale($submission->getLocale());
		$newMediaFile->setGenreId($genreId);
		$newMediaFile->setFileStage(SUBMISSION_FILE_DEPENDENT);
		$newMediaFile->setDateUploaded(Core::getCurrentDate());
		$newMediaFile->setDateModified(Core::getCurrentDate());
		$newMediaFile->setUploaderUserId($userid);
		$newMediaFile->setFileSize(filesize($tmpfname));
		$newMediaFile->setFileType($mediaData["fileType"]);
		$newMediaFile->setAssocId($submissionFile->getFileId());
		$newMediaFile->setAssocType(ASSOC_TYPE_SUBMISSION_FILE);
		$newMediaFile->setOriginalFileName($mediaData["fileName"]);
		$insertedMediaFile = $submissionFileDao->insertObject($newMediaFile, $tmpfname);

		unlink($tmpfname);

		return $insertedMediaFile;
	}

	/**
	 * Update manuscript XML file
	 * @param $fileStage int File stage of the new submission file
	 * @param $genreId int Genre of the new submission file
	 * @param $manuscriptXml string Manuscript XML content
	 * @param $submission Submission Submission to which to attach the new SubmissionFile
	 * @param $submissionFile SubmissionFile Original submission file to update
	 * @param $userid User Submitting user
	 * @return SubmissionFile
	 */
	public function updateManuscriptFile($fileStage, $genreId, $manuscriptXml, $submission, $submissionFile, $userid) {
		$tmpfname = tempnam(sys_get_temp_dir(), 'texture');
		file_put_contents($tmpfname, $manuscriptXml);


		$fileSize = filesize($tmpfname);

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$newSubmissionFile = $submissionFileDao->newDataObjectByGenreId($genreId);

		$newSubmissionFile->setSubmissionId($submission->getId());
		$newSubmissionFile->setSubmissionLocale($submission->getLocale());
		$newSubmissionFile->setGenreId($genreId);
		$newSubmissionFile->setFileStage($fileStage);
		$newSubmissionFile->setDateUploaded(Core::getCurrentDate());
		$newSubmissionFile->setDateModified(Core::getCurrentDate());
		$newSubmissionFile->setOriginalFileName($submissionFile->getOriginalFileName());
		$newSubmissionFile->setUploaderUserId($userid);
		$newSubmissionFile->setFileSize($fileSize);
		$newSubmissionFile->setFileType($submissionFile->getFileType());
		$newSubmissionFile->setSourceFileId($submissionFile->getFileId());
		$newSubmissionFile->setSourceRevision($submissionFile->getRevision());
		$newSubmissionFile->setFileId($submissionFile->getFileId());
		$newSubmissionFile->setRevision($submissionFile->getRevision() + 1);
		$insertedSubmissionFile = $submissionFileDao->insertObject($newSubmissionFile, $tmpfname);

		unlink($tmpfname);

		return $insertedSubmissionFile;
	}

	/**
	 * Suggest a dependent Genre by file type
	 * @param $filetype string mime file type
	 * @return null|int Genre Id if available
	 */
	function suggestDependentGenreId($filetype) {
		$request = $this->getRequest();
		$journal = $request->getJournal();
		$genreDao = DAORegistry::getDAO('GenreDAO');
		$genres = $genreDao->getByDependenceAndContextId(true, $journal->getId());
		$genreId = null;
		import('classes.file.PublicFileManager');
		$publicFileManager = new PublicFileManager();
		$extension = $publicFileManager->getImageExtension($filetype);
		while ($candidateGenre = $genres->next()) {
			if ($extension) {
				if ($candidateGenre->getKey() == 'IMAGE') {
					$genreId = $candidateGenre->getId();
					break;
				}
			} else {
				if ($candidateGenre->getKey() == 'MULTIMEDIA') {
					$genreId = $candidateGenre->getId();
					break;

				}
			}
		}
		return $genreId;
	}
}
