<?php

/**
 * @file classes/form/UploadImageForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class UploadImageForm
 * @ingroup plugins_importexport_quicksubmit_classes_form
 *
 * @brief Form for upload an image.
 */

use PKP\form\Form;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\Request\RemoteActionConfirmationModal;
use PKP\file\PublicFileManager;
use PKP\file\TemporaryFileManager;

class UploadImageForm extends Form {
	/** string Setting key that will be associated with the uploaded file. */
	var $_fileSettingName;

	/** @var $request object */
	var $request;

	/** @var $submissionId int */
	var $submissionId;

	/** @var $submission Submission */
	var $submission;

	/** @var $publication Publication */
	var $publication;

	/** @var $plugin QuickSubmitPlugin */
	var $plugin;

	/** @var $context Journal */
	var $context;

	/**
	 * Constructor.
	 * @param $plugin object
	 * @param $request object
	 */
	function __construct($plugin, $request) {
		parent::__construct($plugin->getTemplateResource('uploadImageForm.tpl'));

		$this->addCheck(new FormValidator($this, 'temporaryFileId', 'required', 'manager.website.imageFileRequired'));

		$this->plugin = $plugin;
		$this->request = $request;
		$this->context = $request->getContext();

		$this->submissionId = $request->getUserVar('submissionId');

		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
		$this->submission = $submissionDao->getById($request->getUserVar('submissionId'), $this->context->getId(), false);
		$this->publication = $this->submission->getCurrentPublication();
	}

	//
	// Extend methods from Form.
	//
	/**
	 * @copydoc Form::getLocaleFieldNames()
	 */
	function getLocaleFieldNames() {
		return array('imageAltText');
	}

	/**
	 * @copydoc Form::initData()
	 */
	function initData() {
		$templateMgr = TemplateManager::getManager($this->request);
		$templateMgr->assign('submissionId', $this->submissionId);

		$locale = Locale::getLocale();
		$coverImage = $this->publication->getData('coverImage', $locale);

		if ($coverImage) {
			$router = $this->request->getRouter();
			$deleteCoverImageLinkAction = new LinkAction(
				'deleteCoverImage',
				new RemoteActionConfirmationModal(
					$this->request->getSession(),
					__('common.confirmDelete'), null,
					$router->url($this->request, null, null, 'importexport', array('plugin', 'QuickSubmitPlugin', 'deleteCoverImage'), array(
						'coverImage' => $coverImage,
						'submissionId' => $this->submission->getId(),
						'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
					)),
					'modal_delete'
				),
				__('common.delete'),
				null
			);
			$templateMgr->assign('deleteCoverImageLinkAction', $deleteCoverImageLinkAction);
		}

		$this->setData('coverImage', $coverImage);
		$this->setData('imageAltText', $coverImage['altText'] ?? '');
	}

	/**
	 * @copydoc Form::readInputData()
	 */
	function readInputData() {
		$this->readUserVars(array('imageAltText', 'temporaryFileId'));
	}

	/**
	 * An action to delete an article cover image.
	 * @param $request PKPRequest
	 * @return JSONMessage JSON object
	 */
	function deleteCoverImage($request) {
		assert($request->getUserVar('coverImage') != '' && $request->getUserVar('submissionId') != '');

		$publicationDao = DAORegistry::getDAO('PublicationDAO'); /* @var $publicationDao PublicationDAO */
		$file = $request->getUserVar('coverImage');

		// Remove cover image and alt text from article settings
		$locale = Locale::getLocale();
		$this->publication->setData('coverImage', []);
		$publicationDao->updateObject($this->publication);

		// Remove the file
		$publicFileManager = new PublicFileManager();
		if ($publicFileManager->removeContextFile($this->submission->getContextId(), $file)) {
			$json = new JSONMessage(true);
			$json->setEvent('fileDeleted');
			return $json;
		} else {
			return new JSONMessage(false, __('editor.article.removeCoverImageFileNotFound'));
		}
	}

	/**
	 * Save file image to Submission
	 * @param $request Request.
	 */
	function execute($request) {
		$publicationDao = DAORegistry::getDAO('PublicationDAO'); /* @var $publicationDao PublicationDAO */

		$temporaryFile = $this->fetchTemporaryFile($request);
		$locale = Locale::getLocale();
		$coverImage = $this->publication->getData('coverImage');

		$publicFileManager = new PublicFileManager();

		if (is_a($temporaryFile, 'TemporaryFile')) {
			$type = $temporaryFile->getFileType();
			$extension = $publicFileManager->getImageExtension($type);
			if (!$extension) {
				return false;
			}
			$locale = Locale::getLocale();

			$newFileName = 'article_' . $this->submissionId . '_cover_' . $locale . $publicFileManager->getImageExtension($temporaryFile->getFileType());

			if ($publicFileManager->copyContextFile($this->context->getId(), $temporaryFile->getFilePath(), $newFileName)) {

				$this->publication->setData('coverImage', [
					'altText' => $this->getData('imageAltText'),
					'uploadName' => $newFileName,
				], $locale);
				$publicationDao->updateObject($this->publication);

				// Clean up the temporary file.
				$this->removeTemporaryFile($request);

				return DAO::getDataChangedEvent();
			}
		} elseif ($coverImage) {
			$coverImage = $this->publication->getData('coverImage');
			$coverImage[$locale]['altText'] = $this->getData('imageAltText');
			$this->publication->setData('coverImage', $coverImage);
			$publicationDao->updateObject($this->publication);
			return DAO::getDataChangedEvent();
		}
		return new JSONMessage(false, __('common.uploadFailed'));

	}

	/**
	 * Get the image that this form will upload a file to.
	 * @return string
	 */
	function getFileSettingName() {
		return $this->_fileSettingName;
	}

	/**
	 * Set the image that this form will upload a file to.
	 * @param $image string
	 */
	function setFileSettingName($fileSettingName) {
		$this->_fileSettingName = $fileSettingName;
	}


	//
	// Implement template methods from Form.
	//
	/**
	 * @see Form::fetch()
	 * @param $params template parameters
	 */
	function fetch($request, $template = null, $display = false, $params = null) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign(array(
			'fileSettingName' => $this->getFileSettingName(),
			'fileType' => 'image',
		));

		return parent::fetch($request, $template, $display);
	}


	//
	// Public methods
	//
	/**
	 * Fecth the temporary file.
	 * @param $request Request
	 * @return TemporaryFile
	 */
	function fetchTemporaryFile($request) {
		$user = $request->getUser();

		$temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO');
		$temporaryFile = $temporaryFileDao->getTemporaryFile(
			$this->getData('temporaryFileId'),
			$user->getId()
		);
		return $temporaryFile;
	}

	/**
	 * Clean temporary file.
	 * @param $request Request
	 */
	function removeTemporaryFile($request) {
		$user = $request->getUser();

		$temporaryFileManager = new TemporaryFileManager();
		$temporaryFileManager->deleteById($this->getData('temporaryFileId'), $user->getId());
	}

	/**
	 * Upload a temporary file.
	 * @param $request Request
	 */
	function uploadFile($request) {
		$user = $request->getUser();

		$temporaryFileManager = new TemporaryFileManager();
		$temporaryFile = $temporaryFileManager->handleUpload('uploadedFile', $user->getId());

		if ($temporaryFile) return $temporaryFile->getId();

		return false;
	}
}

