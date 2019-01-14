<?php
if (!defined('ABSPATH')) {
    die('Access denied.');
}

if (class_exists('NextADInt_Adi_Authentication_PasswordValidationService')) {
    return;
}

/**
 * This class adds a hook for WordPress' "check_password" filter. The check_password filter is executed when the filters wp_authenticate_username_password or wp_authenticate_email_password are triggered.
 * In case of the fallback to a local password, this class intercepts the password check.
 *
 * @author  Tobias Hellmann <the@neos-it.de>
 * @access public
 */
class NextADInt_Adi_Authentication_PasswordValidationService
{
    /* @var NextADInt_Multisite_Configuration_Service $configuration */
    private $configuration;

    /** @var NextADInt_Adi_LoginState */
    private $loginState;

    /* @var Logger $logger */
    private $logger;

    /**
     * @param NextADInt_Adi_LoginState $loginState
     * @param NextADInt_Multisite_Configuration_Service $configuration
     * @param NextADInt_Adi_LoginState $loginState
     */
    public function __construct(NextADInt_Adi_LoginState $loginState,
                                NextADInt_Multisite_Configuration_Service $configuration)
    {
        $this->loginState = $loginState;
        $this->configuration = $configuration;

        $this->logger = NextADInt_Core_Logger::getLogger();
    }

    /**
     * Override WordPress password check (for using Active Directory passwords)
     */
    public function register()
    {
        add_filter('check_password', array($this, 'overridePasswordCheck'), 10, 4);
    }

    /**
     * <ul>
     * <li>The local WordPress password check will be used for user ID 1.</li>
     * <li>The password for authenticated user is always ok.</li>
     * <li>The WordPress password check will be used, if an user is not authenticated, has a samAccountName and FALLBACK_TO_LOCAL_PASSWORD is activated.</li>
     * </ul>
     *
     * @param bool $check
     * @param string $password
     * @param string $hash
     * @param int $userId
     *
     * @return bool
     * This method will check the users credentials if he could not be authenticated by LoginService.php.
     * Logins via SSO (Sso\Service.php) are not relevant for this method: SSO is fired during the "init" action and does not touch the "authenticate" filter for normal authentications.
     *
     * If FALLBACK_TO_LOCAL_PASSWORD is disabled, the credentials of this user (created by this plugin) will be denied.
     * If the user is disabled by this plugin, the credentials of this user will be denied.
     */
    public function overridePasswordCheck($check, $password, $hash, $userId)
    {
        // always use local password handling for user_id 1 (admin)
        if ($userId == 1) {
            $this->logger->debug('UserID 1: using local (WordPress) password check.');

            return $check;
        }

        // return true for users authenticated by ADI (should never happen, but who knows?)
        if ($this->loginState->isAuthenticated()) {
            $this->logger->debug('User has been successfully authenticated by the "Active Directory Integration" plugin: override local (WordPress) password check.');

            return true;
        }

        // only check for local password if this is not an AD user and if fallback to local password is active
        $isActiveDirectoryUser = get_user_meta($userId, NEXT_AD_INT_PREFIX . 'samaccountname', true);

        if (!$isActiveDirectoryUser) {
            // use local password check in all other cases
            $this->logger->debug('Local WordPress user. Using local (WordPress) password check.');

            return $check;
        }

        $fallbackToLocalPassword = $this->configuration->getOptionValue(
            NextADInt_Adi_Configuration_Options::FALLBACK_TO_LOCAL_PASSWORD
        );

        if ($fallbackToLocalPassword) {
            $this->logger->debug('User from AD. Falling back to local (WordPress) password check.');

            return $check;
        }

        $this->logger->debug('User from AD and fallback to local (WordPress) password deactivated. Authentication failed.');

        return false;
    }
}