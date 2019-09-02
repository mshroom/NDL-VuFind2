<?php
/**
 * LibraryCards Controller
 *
 * PHP version 7
 *
 * Copyright (C) Villanova University 2010.
 * Copyright (C) The National Library of Finland 2015-2018.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
namespace Finna\Controller;

use VuFind\Exception\Auth as AuthException;

/**
 * Controller for the library card functionality.
 *
 * @category VuFind
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class LibraryCardsController extends \VuFind\Controller\LibraryCardsController
{
    /**
     * Send user's library card to the edit view
     *
     * @return mixed
     */
    public function editCardAction()
    {
        // Check login here so that we know not to mess with AuthManager
        $user = $this->getUser();
        if ($user == false) {
            return $this->forceLogin();
        }

        $view = parent::editCardAction();

        if (!($view instanceof \Zend\View\Model\ViewModel)) {
            return $view;
        }

        $manager = $this->getAuthManager();
        $options = $manager->getSelectableAuthOptions();
        $originalMethod = $manager->getAuthMethod();
        if (in_array('MultiILS', $options)) {
            $manager->setAuthMethod('MultiILS');
        } else {
            $manager->setAuthMethod('ILS');
        }
        if (!empty($view->targets)) {
            $labels = [];

            foreach ($view->targets as $target) {
                $labels[$target]
                    = $manager->getSecondaryLoginFieldLabel($target);
            }
            $view->secondaryLoginFieldLabels = $labels;
        } else {
            $view->secondaryLoginFieldLabel
                = $manager->getSecondaryLoginFieldLabel();
        }
        $manager->setAuthMethod($originalMethod);

        $view->secondaryUsername = $this->params()->fromPost(
            'secondary_username', ''
        );

        return $view;
    }

    /**
     * Change library card password
     *
     * @return mixed
     */
    public function newPasswordAction()
    {
        // User must be logged in to edit library cards:
        $user = $this->getUser();
        if ($user == false) {
            return $this->forceLogin();
        }

        $id = $this->params()->fromRoute('id', $this->params()->fromQuery('id'));
        $card = $user->getLibraryCard($id);
        if ($id == null || !$card->rowExistsInDatabase()) {
            throw new \Exception('Changing password not supported for this card');
        }

        // Process form submission:
        if ($this->formWasSubmitted('submit')) {
            if ($redirect = $this->processPasswordChange($user, $card)) {
                return $redirect;
            }
        }

        // Connect to the ILS and check if it supports changing password
        $catalog = $this->getILS();
        if (!$catalog->checkFunction('changePassword', ['card' => $card->toArray()])
        ) {
            throw new \Exception('Changing password not supported for this card');
        }
        // It's not exactly correct to send a card to getPasswordPolicy, but it has
        // the required fields..
        $policy = $catalog->getPasswordPolicy($card->toArray());
        if (isset($policy['pattern']) && empty($policy['hint'])) {
            $pattern = $policy['pattern'];
            $policy['hint'] = in_array($pattern, ['numeric', 'alphanumeric'])
                ? 'password_only_' . $pattern : null;
        }

        $user->updateHash();

        // Send the card to the view:
        return $this->createViewModel(
            [
                'card' => $card,
                'hash' => $user->verify_hash,
                'passwordPolicy' => $policy,
                'verifyold' => true
            ]
        );
    }

    /**
     * Recover a library account
     *
     * @return View object
     */
    public function recoverAction()
    {
        // Make sure we're configured to do this
        $target = $this->params()->fromQuery(
            'target', $this->params()->fromPost('target', '')
        );
        $catalog = $this->getILS();
        $recoveryConfig = $catalog->checkFunction(
            'getPasswordRecoveryToken',
            ['patron' => ['cat_username' => "$target.123"]]
        );
        $view = $this->createViewModel(
            [
                'target' => $target
            ]
        );
        if (!$recoveryConfig) {
            $view->recoveryDisabled = true;
        }
        $view->useRecaptcha = $this->recaptcha()->active('passwordRecovery');
        // If we have a submitted form
        if ($recoveryConfig
            && $this->formWasSubmitted('submit', $view->useRecaptcha)
        ) {
            // Check if we have a submitted form, and use the information
            // to get the user's information
            $username = $this->params()->fromPost('username');
            $email = $this->params()->fromPost('email');

            $result = $catalog->getPasswordRecoveryToken(
                [
                    'cat_username' => "$target.$username",
                    'email' => $email
                ]
            );

            if (!empty($result['success'])) {
                // Make totally sure the timestamp is exactly 10 characters:
                $time
                    = str_pad(substr((string)time(), 0, 10), 10, '0', STR_PAD_LEFT);
                $hash = md5($username . $email . rand()) . $time;

                $finnaCache = $this->getTable('FinnaCache');
                $row = $finnaCache->createRow();
                $row->resource_id = $hash . '.recovery_hash';
                $row->mtime = time();
                $row->data = json_encode(
                    [
                        'target' => $target,
                        'username' => $username,
                        'email' => $email,
                        'token' => $result['token']
                    ]
                );
                $row->save();
                $this->sendRecoveryEmail(
                    $email,
                    $target,
                    [
                        'hash' => $hash
                    ]
                );
                $view->emailSent = true;
                $this->flashMessenger()
                    ->addMessage('library_card_recovery_email_sent', 'success');
            } else {
                $this->flashMessenger()->addErrorMessage('recovery_user_not_found');
            }
        }
        return $view;
    }

    /**
     * Handling submission of a new password for a library card.
     *
     * @return view
     */
    public function resetPasswordAction()
    {
        if ($this->getUser()) {
            return $this->redirect()->toRoute(
                'myresearch-home', [], ['query' => ['redirect' => 0]]
            );
        }

        $hash = $this->params()->fromQuery(
            'hash', $this->params()->fromPost('hash', '')
        );
        // Make sure to not include '>' if the mail client doesn't handle links
        // properly
        $hash = preg_replace('/>$/', '', $hash);

        // Check if hash is expired
        $hashtime = $this->getHashAge($hash);
        $hashLifetime = isset($config->Authentication->recover_hash_lifetime)
            ? $config->Authentication->recover_hash_lifetime
            : 1209600; // Two weeks
        if (time() - $hashtime > $hashLifetime) {
            error_log(
                "Recovery hash expired: $hash, time: $hashtime,"
                . " lifetime: $hashLifetime, hash age: " . (time() - $hashtime)
                . ", query: " . $_SERVER['QUERY_STRING']
            );
            $this->flashMessenger()->addErrorMessage('recovery_expired_hash');
            return $this->redirect()->toRoute(
                'myresearch-home', [], ['query' => ['redirect' => 0]]
            );
        }

        $finnaCache = $this->getTable('FinnaCache');
        $recoveryRecord = $finnaCache->getByResourceId("$hash.recovery_hash");
        if (!$recoveryRecord) {
            $this->flashMessenger()->addMessage('recovery_invalid_hash', 'error');
            return $this->redirect()->toRoute(
                'myresearch-home', [], ['query' => ['redirect' => 0]]
            );
        }
        $recoveryData = json_decode($recoveryRecord->data, true);

        $target = $recoveryData['target'];
        $catalog = $this->getILS();
        $recoveryConfig = $catalog->checkFunction(
            'recoverPassword',
            ['patron' => ['cat_username' => "$target." . $recoveryData['username']]]
        );
        if (!$recoveryConfig) {
            $this->flashMessenger()->addMessage('recovery_disabled', 'error');
            return $this->redirect()->toRoute(
                'myresearch-home', [], ['query' => ['redirect' => 0]]
            );
        }
        $policy = $catalog->getPasswordPolicy(['cat_username' => "$target.123"]);
        if (isset($policy['pattern']) && empty($policy['hint'])) {
            $policy['hint']
                = in_array($policy['pattern'], ['numeric', 'alphanumeric'])
                    ? 'password_only_' . $policy['pattern'] : null;
        }
        $view = $this->createViewModel(
            [
                'target' => $target,
                'hash' => $hash,
                'passwordPolicy' => $policy
            ]
        );
        $view->useRecaptcha = $this->recaptcha()->active('changePassword');
        // Check reCaptcha
        if ($this->formWasSubmitted('submit', $view->useRecaptcha)) {
            $password = $this->params()->fromPost('password', '');
            $password2 = $this->params()->fromPost('password2', '');
            if ($password !== $password2) {
                $this->flashMessenger()->addErrorMessage('Passwords do not match');
                return $view;
            }

            $recoveryRecord->delete();

            $result = $catalog->recoverPassword(
                [
                    'cat_username' => "$target." . $recoveryData['username'],
                    'email' => $recoveryData['email'],
                    'token' => $recoveryData['token'],
                    'password' => $password
                ]
            );

            if (!empty($result['success'])) {
                $this->flashMessenger()->addSuccessMessage('new_password_success');
            } else {
                $this->flashMessenger()->addErrorMessage('recovery_user_not_found');
            }
            return $this->redirect()->toRoute(
                'myresearch-home', [], ['query' => ['redirect' => 0]]
            );
        }
        return $view;
    }

    /**
     * Process the "edit library card" submission.
     *
     * @param \VuFind\Db\Row\User $user Logged in user
     *
     * @return object|bool        Response object if redirect is
     * needed, false if form needs to be redisplayed.
     */
    protected function processEditLibraryCard($user)
    {
        $cardName = $this->params()->fromPost('card_name', '');
        $target = $this->params()->fromPost('target', '');
        $username = $this->params()->fromPost('username', '');
        $password = $this->params()->fromPost('password', '');

        if (!$username || !$password) {
            $this->flashMessenger()
                ->addMessage('authentication_error_blank', 'error');
            return false;
        }

        if ($target) {
            $username = "$target.$username";
        }

        // Check for a secondary username
        $secondaryUsername = trim($this->params()->fromPost('secondary_username'));

        // Connect to the ILS and check that the credentials are correct:
        $catalog = $this->getILS();
        $patron = $catalog->patronLogin($username, $password, $secondaryUsername);
        if (!$patron) {
            $this->flashMessenger()
                ->addMessage('authentication_error_invalid', 'error');
            return false;
        }

        $id = $this->params()->fromRoute('id', $this->params()->fromQuery('id'));

        if (!empty($cardName)) {
            list($cardInstitution) = explode('.', $username, 2);
            foreach ($user->getLibraryCards() as $otherCard) {
                if ($otherCard->id == $id) {
                    continue;
                }
                list($otherInstitution) = explode('.', $otherCard->cat_username, 2);
                if ($cardInstitution == $otherInstitution
                    && strcasecmp($cardName, $otherCard->card_name) == 0
                ) {
                    $this->flashMessenger()->addMessage(
                        'library_card_name_exists', 'error'
                    );
                    return false;
                }
            }
        }

        try {
            $user->saveLibraryCard(
                $id == 'NEW' ? null : $id, $cardName, $username, $password
            );
        } catch (\VuFind\Exception\LibraryCard $e) {
            $this->flashMessenger()->addMessage($e->getMessage(), 'error');
            return false;
        }

        return $this->redirect()->toRoute('librarycards-home');
    }

    /**
     * Process the "change password" submission.
     *
     * @param \VuFind\Db\Row\User     $user Logged in user
     * @param \VuFind\Db\Row\UserCard $card Library card
     *
     * @return object|bool Response object if redirect is needed, false if form
     * needs to be redisplayed.
     */
    protected function processPasswordChange($user, $card)
    {
        $post = $this->getRequest()->getPost();
        $userFromHash = isset($post->hash)
            ? $this->getTable('User')->getByVerifyHash($post->hash)
            : false;

        $oldPassword = $this->params()->fromPost('oldpwd', '');
        $password = $this->params()->fromPost('password', '');
        $password2 = $this->params()->fromPost('password2', '');

        // Validate new password
        try {
            $ilsAuth = $this->serviceLocator->get(\VuFind\Auth\PluginManager::class)
                ->get('ILS');
            $ilsAuth->validatePasswordInUpdate(
                ['password' => $password, 'password2' => $password2]
            );
        } catch (AuthException $e) {
            $this->flashMessenger()->addMessage($e->getMessage(), 'error');
            return false;
        }

        // Missing or invalid hash
        if (false == $userFromHash) {
            $this->flashMessenger()->addMessage('recovery_user_not_found', 'error');
            return false;
        } elseif ($userFromHash->username !== $user->username) {
            $this->flashMessenger()
                ->addMessage('authentication_error_invalid', 'error');
            return false;
        }

        // Connect to the ILS and check that the credentials are correct:
        $catalog = $this->getILS();
        $patron = $catalog->patronLogin($card->cat_username, $oldPassword);
        if (!$patron) {
            $this->flashMessenger()
                ->addMessage('authentication_error_invalid', 'error');
            return false;
        }

        $result = $catalog->changePassword(
            [
                'patron' => $patron,
                'oldPassword' => $oldPassword,
                'newPassword' => $password
            ]
        );
        if (!$result['success']
            && $result['status'] == 'authentication_error_invalid'
            && !empty($oldPassword)
        ) {
            // Try again with empty old password just in case this was a user that
            // was logged in with the fallback login field
            $result = $catalog->changePassword(
                [
                    'patron' => $patron,
                    'oldPassword' => '',
                    'newPassword' => $password
                ]
            );
        }
        if (!$result['success']) {
            $this->flashMessenger()->addMessage($result['status'], 'error');
            return false;
        }
        $user->saveLibraryCard(
            $card->id, $card->card_name, $card->cat_username, $password
        );
        if (strcasecmp($user->cat_username, $card->cat_username) === 0) {
            $user->saveCredentials($card->cat_username, $password);
        }
        $user->updateHash();

        $this->flashMessenger()->addSuccessMessage('new_password_success');

        return $this->redirect()->toRoute('librarycards-home');
    }

    /**
     * Helper function for recoverAction
     *
     * @param string $email     User's email address
     * @param string $target    Login target
     * @param array  $urlParams Recovery URL params
     *
     * @return void (sends email or adds error message)
     */
    protected function sendRecoveryEmail($email, $target, $urlParams)
    {
        // Attempt to send the email
        try {
            $config = $this->getConfig();
            $renderer = $this->getViewRenderer();
            $library = !empty($target)
                ? $this->translate("source_$target", null, $target)
                : $config->Site->title;
            // Custom template for emails (text-only)
            $message = $renderer->render(
                'Email/recover-library-card-password.phtml',
                [
                    'library' => $library,
                    'url' => $this->getServerUrl('librarycards-resetpassword')
                        . '?' . http_build_query($urlParams)
                ]
            );
            $config = $this->getConfig();
            $subject = $this->translate(
                'library_card_recovery_email_subject',
                [
                    '%%library%%' => $library
                ]
            );
            $this->serviceLocator->get(\VuFind\Mailer\Mailer::class)->send(
                $email,
                $config->Site->email,
                $subject,
                $message
            );
        } catch (MailException $e) {
            $this->flashMessenger()->addMessage($e->getMessage(), 'error');
        }
    }

    /**
     * Helper function for verification hashes
     *
     * @param string $hash User-unique hash string from request
     *
     * @return int age in seconds
     */
    protected function getHashAge($hash)
    {
        return intval(substr($hash, -10));
    }
}
