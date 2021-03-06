<?php
/**
 * Copyright © 2019 O2TI. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace O2TI\SocialLogin\Provider;

use Hybridauth\HybridauthFactory;
use Hybridauth\User\Profile as SocialProfile;
use Magento\Customer\Model\Account\Redirect as AccountRedirect;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Customer\Model\Session\Proxy as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Provider
{
    const CONFIG_PATH_SOCIAL_LOGIN_PROVIDER_ENABLED = 'social_login/%s/enabled';
    const CONFIG_PATH_SOCIAL_LOGIN_PROVIDER_KEY = 'social_login/%s/api_key';
    const CONFIG_PATH_SOCIAL_LOGIN_PROVIDER_SECRET = 'social_login/%s/api_secret';
    const COOKIE_NAME = 'social_login_redirect';

    /**
     * The providers we currently support.
     */
    const PROVIDERS = [
        'facebook',
        'google',
        'WindowsLive',
    ];

    /**
     * @var CustomerFactory
     */
    private $customerFactory;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var CustomerResource
     */
    private $customerResource;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @var HybridauthFactory
     */
    private $hybridauthFactory;

    /**
     * @var AccountRedirect
     */
    protected $accountRedirect;

    /**
     * @var CookieManagerInterface
     */
    private $cookieManager;

    /**
     * @var CookieMetadataFactory
     */
    private $cookieMetadataFactory;

    /**
     * @var SessionManagerInterface
     */
    private $sessionManager;

    /**
     * @param HybridauthFactory       $hybridauthFactory
     * @param UrlInterface            $url
     * @param CustomerFactory         $customerFactory
     * @param CustomerResource        $customerResource
     * @param CustomerSession         $customerSession
     * @param StoreManagerInterface   $storeManager
     * @param ScopeConfigInterface    $scopeConfig
     * @param CookieManagerInterface  $cookieManager
     * @param CookieMetadataFactory   $cookieMetadataFactory
     * @param SessionManagerInterface $sessionManager
     */
    public function __construct(
        HybridauthFactory $hybridauthFactory,
        UrlInterface $url,
        CustomerFactory $customerFactory,
        CustomerResource $customerResource,
        CustomerSession $customerSession,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        CookieManagerInterface $cookieManager = null,
        CookieMetadataFactory $cookieMetadataFactory = null,
        SessionManagerInterface $sessionManager
    ) {
        $this->hybridauthFactory = $hybridauthFactory;
        $this->customerFactory = $customerFactory;
        $this->customerSession = $customerSession;
        $this->customerResource = $customerResource;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->url = $url;
        $this->cookieManager = $cookieManager ?:
            ObjectManager::getInstance()->get(CookieManagerInterface::class);
        $this->cookieMetadataFactory = $cookieMetadataFactory ?:
            ObjectManager::getInstance()->get(CookieMetadataFactory::class);
        $this->sessionManager = $sessionManager;
    }

    /**
     * Get account redirect.
     *
     * @deprecated 100.0.10
     *
     * @return AccountRedirect
     */
    protected function getAccountRedirect()
    {
        if (!is_object($this->accountRedirect)) {
            $this->accountRedirect = ObjectManager::getInstance()->get(AccountRedirect::class);
        }

        return $this->accountRedirect;
    }

    /**
     * Configs.
     *
     * @return array
     */
    private function getProvidersConfig($provider)
    {
        $config = [];
        $config[$provider] = [
            'enabled' => (bool) $this->scopeConfig->getValue(
                sprintf(self::CONFIG_PATH_SOCIAL_LOGIN_PROVIDER_ENABLED, $provider),
                ScopeInterface::SCOPE_STORE
            ),
            'keys' => [
                'key' => $this->scopeConfig->getValue(
                    sprintf(self::CONFIG_PATH_SOCIAL_LOGIN_PROVIDER_KEY, $provider),
                    ScopeInterface::SCOPE_STORE
                ),
                'secret' => $this->scopeConfig->getValue(
                    sprintf(self::CONFIG_PATH_SOCIAL_LOGIN_PROVIDER_SECRET, $provider),
                    ScopeInterface::SCOPE_STORE
                ),
            ],
        ];

        return $config;
    }

    /**
     * Endpoint.
     *
     * @param string $provider
     *
     * @return string
     */
    private function getEndpoint($provider)
    {
        $params = [
            '_secure'  => true,
            'provider' => $provider,
        ];

        return $this->url->getUrl('sociallogin/endpoint/index', $params);
    }

    /**
     * Gets customer data for a hybrid auth profile.
     *
     * @param SocialProfile $profile
     *
     * @return array
     */
    private function getCustomerData(SocialProfile $profile)
    {
        $customerData = [];
        foreach (['firstName', 'lastName', 'email'] as $field) {
            $data = $profile->{$field};
            $customerData[strtolower($field)] = $data !== null ? $data : '-';
        }

        return $customerData;
    }

    /**
     * Set Customer.
     *
     * @param SocialProfile $profile
     *
     * @return customer
     */
    private function setCustomerData(SocialProfile $socialProfile)
    {
        $websiteId = $this->storeManager->getWebsite()->getId();
        $customer = $this->customerFactory->create();
        $customer->setWebsiteId($websiteId);
        $customer->loadByEmail($socialProfile->email);
        if (!$customer->getId()) {
            $customer->setData('email', $socialProfile->email);
            $customer->addData($this->getCustomerData($socialProfile));
            $this->customerResource->save($customer);
        }

        return $customer;
    }

    /**
     * Login account.
     *
     * @param string $provider
     *
     * @throws LocalizedException
     */
    public function login($provider)
    {
        $hybridAuth = $this->hybridauthFactory->create([
            'config' => [
                'callback'  => $this->getEndpoint($provider),
                'providers' => $this->getProvidersConfig($provider),
            ],
        ]);
        $authenticate = $hybridAuth->authenticate($provider);
        if ($authenticate->isConnected()) {
            $socialProfile = $authenticate->getUserProfile();
            $customer = $this->setCustomerData($socialProfile);
            $this->customerSession->setCustomerAsLoggedIn($customer);
        }
    }

    /**
     * Referer.
     *
     * @param $provider
     * @param $isSecure
     * @param $referer
     *
     * @return AccountRedirect
     */
    public function setAutenticateAndReferer($provider, $isSecure = 1, $referer = null)
    {
        if ($referer) {
            $metadata = $this->cookieMetadataFactory
                        ->createPublicCookieMetadata()
                        ->setDuration(86400)
                        ->setPath($this->sessionManager->getCookiePath())
                        ->setSecure($isSecure)
                        ->setHttpOnly(true)
                        ->setDomain($this->sessionManager->getCookieDomain());
            $this->cookieManager->setPublicCookie(
                self::COOKIE_NAME,
                $referer,
                $metadata
            );
        }

        $hybridAuth = $this->hybridauthFactory->create([
            'config' => [
                'callback'  => $this->getEndpoint($provider),
                'providers' => $this->getProvidersConfig($provider),
            ],
        ]);

        $authenticate = $hybridAuth->authenticate($provider);

        if ($authenticate->isConnected()) {
            $socialProfile = $authenticate->getUserProfile();
            $customer = $this->setCustomerData($socialProfile);
            $this->customerSession->setCustomerAsLoggedIn($customer);
            $response['redirectUrl'] = $this->cookieManager->getCookie(self::COOKIE_NAME);
        }

        return $response;
    }

    /**
     * Account redirect setter for unit tests.
     *
     * @deprecated 100.0.10
     *
     * @param AccountRedirect $value
     *
     * @return void
     */
    public function setAccountRedirect($value)
    {
        $this->accountRedirect = $value;
    }
}
