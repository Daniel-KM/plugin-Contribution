<?php
/**
 * @version $Id$
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @copyright Center for History and New Media, 2010
 * @package Contribution
 */
 
/**
 * Controller for contributions themselves.
 */
class Contribution_ContributionController extends Omeka_Controller_AbstractActionController
{   
    protected $_captcha;
    
    /**
     * Index action; simply forwards to contributeAction.
     */
    public function indexAction()
    {
        $this->_forward('contribute');
    }
    
    public function myContributionsAction()
    {
        $user = current_user();
        $contribItemTable = $this->_helper->db->getTable('ContributionContributedItem');
                
        $contribItems = array();
        if(!empty($_POST)) {            
            foreach($_POST['contribution_public'] as $id=>$value) {
                $contribItem = $contribItemTable->find($id);
                if($value) {
                    $contribItem->public = true;
                } else {
                    $contribItem->makeNotPublic();
                }
                $contribItem->public = $value;
                $contribItem->anonymous = $_POST['contribution_anonymous'][$id];
                
                if($contribItem->save()) {
                    $this->_helper->flashMessenger( __('Your contributions have been updated.'), 'success');
                } else {
                    $this->_helper->flashMessenger($contribItem->getErrors());
                }
                
                $contribItems[] = $contribItem;
            }
        } else {
            $contribItems = $contribItemTable->findBy(array('contributor'=>$user->id));
        }
        
        $this->view->contrib_items = $contribItems;
        
    }
    
    /**
     * Action for main contribution form.
     */
    public function contributeAction()
    {
        if ($this->_processForm($_POST)) {
            $route = $this->getFrontController()->getRouter()->getCurrentRouteName();
            $this->_helper->_redirector->gotoRoute(array('action' => 'thankyou'), $route);
        } else {

            $typeId = null;
            if (isset($_POST['contribution_type']) && ($postedType = $_POST['contribution_type'])) {
                $typeId = $postedType;
            } else if ($defaultType = get_option('contribution_default_type')) {
                $typeId = $defaultType;
            }
            if ($typeId) {
                $this->_setupContributeSubmit($typeId);
                $this->view->typeForm = $this->view->render('contribution/type-form.php');
            }
            
            
            if(isset($this->_profile) && !$this->_profile->exists()) {
                $this->_helper->flashMessenger($this->_profile->getErrors(), 'error');
                return;
            }            
        }
    }
    
    /**
     * Action for AJAX request from contribute form.
     */
    public function typeFormAction()
    {
        $this->_setupContributeSubmit($_POST['contribution_type']);
    }
    
    /**
     * Displays terms of service for contribution.
     */
    public function termsAction()
    {
    }
    
    /**
     * Displays a "Thank You" message to users who have contributed an item 
     * through the public form.
     */
    public function thankyouAction()
    {
    }
    
    /**
     * Common tasks whenever displaying submit form for contribution.
     *
     * @param int $typeId ContributionType id
     */
    public function _setupContributeSubmit($typeId)
    {
        // Override default element form display        
        $this->view->addHelperPath(CONTRIBUTION_HELPERS_DIR, 'Contribution_View_Helper');
        $item = new Item;
        $this->view->item = $item;
        
        $type = get_db()->getTable('ContributionType')->find($typeId);
        $this->view->type = $type;
        
        //setup profile stuff, if needed
        $profileTypeId = get_option('contribution_user_profile_type');
        if(plugin_is_active('UserProfiles') && $profileTypeId) {
            $this->view->addHelperPath(USER_PROFILES_DIR . '/helpers', 'UserProfiles_View_Helper_');
            $profileType = $this->_helper->db->getTable('UserProfilesType')->find($profileTypeId);
            $this->view->profileType = $profileType;
            
            $profile = $this->_helper->db->getTable('UserProfilesProfile')->findByUserIdAndTypeId(current_user()->id, $profileTypeId);
            if(!$profile) {
                $profile = new UserProfilesProfile();
                $profile->type_id = $profileTypeId;
            }
            $this->view->profile = $profile;            
        }
    }
    
    /**
     * Handle the POST for adding an item via the public form.
     * 
     * Validate and save the contribution to the database.  Save the ID of the
     * new item to the session.  Redirect to the consent form. 
     * 
     * If validation fails, render the Contribution form again with errors.
     *
     * @param array $post POST array
     * @return bool
     */
    protected function _processForm($post)
    {    
        if (!empty($post)) {
            // The final form submit was not pressed.
            if (!isset($post['form-submit'])) {
                return false;
            }
            
            if (!$this->_validateContribution($post)) {
                return false;
            }
            
            $contributionTypeId = trim($post['contribution_type']);
            if ($contributionTypeId !== "" && is_numeric($contributionTypeId)) {
                $contributionType = get_db()->getTable('ContributionType')->find($contributionTypeId);
                $itemTypeId = $contributionType->getItemType()->id;
            } else {
            	$this->_helper->flashMessenger(__('You must select a type for your contribution.'), 'error');
                return false;
            }

            $itemMetadata = array('public'       => false,
                                  'featured'     => false,
                                  'item_type_id' => $itemTypeId);
            
            $collectionId = get_option('contribution_collection_id');
            if (!empty($collectionId) && is_numeric($collectionId)) {
                $itemMetadata['collection_id'] = (int) $collectionId;
            }
            
            $fileMetadata = $this->_processFileUpload($contributionType);

            // This is a hack to allow the file upload job to succeed
            // even with the synchronous job dispatcher.
            if ($acl = get_acl()) {
                $acl->allow(null, 'Items', 'showNotPublic');
            }
            try {
                $item = insert_item($itemMetadata, array(), $fileMetadata);
            } catch(Omeka_Validator_Exception $e) {
                $this->flashValidatonErrors($e);
                return false;
            } catch (Omeka_File_Ingest_InvalidException $e) {
                // Copying this cruddy hack
                if (strstr($e->getMessage(), "The file 'contributed_file' was not uploaded")) {
                   $this->_helper->flashMessenger("You must upload a file when making a {$contributionType->display_name} contribution.", 'error');
                } else {
                    $this->_helper->flashMessenger($e->getMessage());
                }
                return false;
            } catch (Exception $e) {
                $this->_helper->flashMessenger($e->getMessage());
                return false;
            }
            $this->_addElementTextsToItem($item, $post['Elements']);
            // Allow plugins to deal with the inputs they may have added to the form.
            fire_plugin_hook('contribution_save_form', array('contributionType'=>$contributionType,'item'=>$item, 'post'=>$post));
            $item->save();
            
            if(! $this->_processUserProfile($post) ) {
                return false;
            }
            
            $this->_linkItemToContributedItem($item, $contributor, $post);
            $user = current_user();
            $this->_sendEmailNotification($user->email, $item);
            return true;
        }
        return false;
    }
    
    protected function _processUserProfile($post)
    {
        $profileTypeId = get_option('contribution_user_profile_type');
        if($profileTypeId) {
            $user = current_user();
            $profile = $this->_helper->db->getTable('UserProfilesProfile')->findByUserIdAndTypeId($user->id, $profileTypeId);
            if(!$profile) {
                $profile = new UserProfilesProfile();
                $profile->setOwner($user);
                $profile->type_id = $profileTypeId;
                $profile->public = 0;
                $profile->setRelationData(array('subject_id'=>$user->id));
            }    
        }
        $profile->setPostData($post);
        $this->_profile = $profile;
        if(!$profile->save(false)) {
            return false;
        }
        return true;
    }
    
    /**
     * Deals with files specified on the contribution form.
     *
     * @param ContributionType $contributionType Type of contribution.
     * @return array File upload array.
     */
    protected function _processFileUpload($contributionType) {
        if ($contributionType->isFileAllowed()) {
            $options = array();
            if ($contributionType->isFileRequired()) {
                $options['ignoreNoFile'] = false;
            } else {
                $options['ignoreNoFile'] = true;
            }

            $fileMetadata = array(
                'file_transfer_type' => 'Upload',
                'files' => 'contributed_file',
                'file_ingest_options' => $options
            );

            // Add the whitelists for uploaded files
            $fileValidation = new ContributionFileValidation;
            $fileValidation->enableFilter();

            return $fileMetadata;
        }
        return array();
    }

    protected function _linkItemToContributedItem($item, $contributor, $post)
    {
        $linkage = new ContributionContributedItem;
        $linkage->contributor_id = $contributor->id;
        $linkage->item_id = $item->id;
        $linkage->public = $post['contribution-public'];
        $linkage->anonymous = $post['contribution-anonymous'];
        $linkage->save();
    }
    
    /**
     * Adds ElementTexts to item.
     *
     * @param Item $item Item to add texts to.
     * @param array $elements Array of element inputs from form
     */
    protected function _addElementTextsToItem($item, $elements)
    {
        $elementTable = get_db()->getTable('Element');
        foreach($elements as $elementId => $elementTexts) {
            $element = $elementTable->find($elementId);
            foreach($elementTexts as $elementText) {
                if (!empty($elementText['text'])) {
                    $item->addTextForElement($element, $elementText['text']);
                }
            }
        }
    }
    
    /**
     * Validate the contribution form submission.
     * 
     * Will flash validation errors that occur.
     * 
     * Verify the validity of the following form elements:
     *      Terms agreement
     *      
     * @return bool
     */
    protected function _validateContribution($post)
    {
        if (!@$post['terms-agree']) {
            $this->_helper->flashMessenger(__('You must agree to the Terms and Conditions.'), 'error');
            return false;
        }
        return true;
    }
    
    /**
     * Send an email notification to the user who contributed the Item.
     * 
     * This email will appear to have been sent from the address specified via
     * the 'contribution_email_sender' option.
     * 
     * @param string $email Address to send to.
     * @param Item $item Item that was contributed via the form.
     * @return void
     * @todo Update for new Contribution
     */
    protected function _sendEmailNotification($toEmail, $item)
    {
        $fromAddress = get_option('contribution_email_sender');
        $siteTitle = get_option('site_title');

        $this->view->item = $item;
        
        //If this field is empty, don't send the email
        if (!empty($fromAddress)) {
            $contributorMail = new Zend_Mail;
            $body = "Thank you for your contribution to " . get_option('site_title') . ".\n";
            $body .= "Your contribution has been accepted and will be preserved in the digital archive. For your records, the permanent URL for your contribution is noted at the end of this email. Please note that contributions may not appear immediately on the website while they await processing by project staff.";
	        $body .= "Contribution URL (pending review by project staff): " . record_url($item, 'show', true);
            
            
            $contributorMail->setBodyText($body);
            $contributorMail->setFrom($fromAddress, "$siteTitle Administrator");
            $contributorMail->addTo($toEmail);
            $contributorMail->setSubject("Your $siteTitle Contribution");
            $contributorMail->addHeader('X-Mailer', 'PHP/' . phpversion());
            try {
                $contributorMail->send();
            } catch (Zend_Mail_Exception $e) {
                _log($e);
            }
        }

        $fromAddress = get_option('administrator_email');
        $toAddresses = explode("\n", get_option('contribution_email_recipients'));

        foreach ($toAddresses as $toAddress) {
            if (empty($toAddress)) {
                continue;
            }
            $adminMail = new Zend_Mail;
            $body = "A new contribution to " . get_option('site_title') . " has been made.";
            set_theme_base_url('admin');
            $body .= "Contribution URL for review: " . record_url($item, 'show', true);
            revert_theme_base_url();
            $adminMail->setBodyText($body);
            $adminMail->setFrom($fromAddress, "$siteTitle");
            $adminMail->addTo($toAddress);
            $adminMail->setSubject("New $siteTitle Contribution");
            $adminMail->addHeader('X-Mailer', 'PHP/' . phpversion());
            try {
                $adminMail->send();
            } catch (Zend_Mail_Exception $e) {
                _log($e);
            }
        }
    }
}
