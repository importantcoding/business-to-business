<?php
/**
 * Business To Business plugin for Craft CMS 3.x
 *
 * Allow businesses to create vouchers for employees that allows the purchasing of products to be charged to the company at a later date.
 *
 * @link      http://importantcoding.com
 * @copyright Copyright (c) 2019 Darryl Hardin
 */

namespace importantcoding\businesstobusiness;

use importantcoding\businesstobusiness\adjusters\VoucherAdjuster;
use importantcoding\businesstobusiness\services\Employee as EmployeeService;
use importantcoding\businesstobusiness\services\Business as BusinessService;
use importantcoding\businesstobusiness\services\Voucher as VoucherService;
use importantcoding\businesstobusiness\services\ShippingRulesBusinesses as ShippingRulesBusinessesService;
use importantcoding\businesstobusiness\services\GatewayRulesBusinesses as GatewayRulesBusinessesService;
use importantcoding\businesstobusiness\variables\BusinessToBusinessVariable;
use importantcoding\businesstobusiness\models\Settings;
use importantcoding\businesstobusiness\elements\Voucher as VoucherElement;
use importantcoding\businesstobusiness\fields\Voucher as VoucherField;
use importantcoding\businesstobusiness\elements\Employee as EmployeeElement;
use importantcoding\businesstobusiness\fields\Employee as EmployeeField;


use Craft;
use craft\base\Plugin;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Plugins;
use craft\services\Users;
use craft\services\UserPermissions;
use craft\events\PluginEvent;
use craft\web\UrlManager;
use craft\services\Elements;
use craft\services\Fields;
use craft\web\twig\variables\CraftVariable;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\commerce\services\ShippingMethods;
// use craft\events\UserAssignGroupEvent;
// use craft\events\RegisterCpNavItemsEvent;
// use craft\web\twig\variables\Cp;
use craft\helpers\UrlHelper;
use craft\commerce\elements\Order;
use craft\commerce\services\OrderAdjustments;
use craft\commerce\events\MatchLineItemEvent;
use craft\commerce\services\Discounts;
use craft\commerce\events\RegisterAvailableShippingMethodsEvent;
use craft\commerce\events\TransactionEvent;
use craft\commerce\services\Transactions;
use craft\commerce\events\DefaultOrderStatusEvent;
use craft\commerce\services\OrderStatuses;
use DateTime;
use yii\base\Event;

/**
 *
 * @author    Darryl Hardin
 * @package   BusinessToBusiness
 * @since     1.0.0
 *
 * @property  ShippingRulesBusinessesService $shippingRulesBusinesses
 * @property  GatewayRulesBusinessesService $gatewayRulesBusinesses
 * @property  EmployeeService $employee
 * @property  BusinessService $business
 * @property  VoucherService $voucher
 * @property  Settings $settings
 * @method    Settings getSettings()
 */
class BusinessToBusiness extends Plugin
{

    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * BusinessToBusiness::$plugin
     *
     * @var BusinessToBusiness
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public $schemaVersion = '1.0.0';
    public $hasCpSettings = true;
    public $hasCpSection = true;

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * BusinessToBusiness::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Register our site routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['employee'] = 'business-to-business/employee-site/index';
                // $event->rules['employee/<businessHandle:{handle}>/<voucherHandle:{handle}>'] = 'business-to-business/employee-site/voucher';
                $event->rules['siteActionTrigger2'] = 'business-to-business/business';
                $event->rules['siteActionTrigger3'] = 'business-to-business/voucher';
                $event->rules['siteActionTrigger4'] = 'business-to-business/cart';
                $event->rules['siteActionTrigger5'] = 'business-to-business/downloads';
            }
        );

        // Register our CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['business-to-business/business'] = 'business-to-business/business/index';

                $event->rules['business-to-business/settings/default'] = 'business-to-business/base/settings';
                $event->rules['business-to-business/settings/employee-settings'] = 'business-to-business/employee-settings/edit';

                $event->rules['business-to-business/business/new'] = 'business-to-business/business/edit';
                $event->rules['business-to-business/business/<businessId:\d+>'] = 'business-to-business/business/edit';
                
                $event->rules['business-to-business/vouchers/<businessHandle:{handle}>'] = 'business-to-business/vouchers/index';
                $event->rules['business-to-business/vouchers/<businessHandle:{handle}>/new'] = 'business-to-business/vouchers/edit';
                $event->rules['business-to-business/vouchers/<businessHandle:{handle}>/<voucherId:\d+>'] = 'business-to-business/vouchers/edit';
                $event->rules['business-to-business/vouchers/<businessHandle:{handle}>/new/<siteHandle:\w+>'] = 'business-to-business/vouchers/edit';
                $event->rules['business-to-business/vouchers/<businessHandle:{handle}>/<businessId:\d+>'] = 'business-to-business/vouchers/edit';
                $event->rules['business-to-business/vouchers/<businessHandle:{handle}>/<businessId:\d+>/<siteHandle:\w+>'] = 'business-to-business/vouchers/edit';

                $event->rules['business-to-business/employees'] = 'business-to-business/employees/index';
                $event->rules['business-to-business/employees/<businessHandle:{handle}>'] = 'business-to-business/employees/index';
                // $event->rules['business-to-business/employees/<businessHandle:{handle}>/new'] = 'business-to-business/employees/edit';
                $event->rules['business-to-business/employees/<businessHandle:{handle}>/<employeeId:\d+>'] = 'business-to-business/employees/edit';
                $event->rules['business-to-business/employees/<businessHandle:{handle}>/new/<siteHandle:\w+>'] = 'business-to-business/employees/edit';
                $event->rules['business-to-business/employees/<businessHandle:{handle}>/<businessId:\d+>'] = 'business-to-business/employees/edit';
                $event->rules['business-to-business/employees/<businessHandle:{handle}>/<businessId:\d+>/<siteHandle:\w+>'] = 'business-to-business/employees/edit';
                $event->rules['business-to-business/employees/delete/<businessId:\d+>'] = 'business-to-business/employees/delete';

                $event->rules['business-to-business/orders/index'] = 'business-to-business/orders/index';
                $event->rules['business-to-business/orders/<businessId:\d+>'] = 'business-to-business/orders/business';

                $event->rules['business-to-business/downloads/business/<businessId:\d+>'] = 'business-to-business/downloads/business';

                
            }
        );


        // Register new adjustment
        Event::on(OrderAdjustments::class, OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS, function(RegisterComponentTypesEvent $event) {
            $event->types[] = VoucherAdjuster::class;
        });

        // Register our elements
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = VoucherElement::class;
                $event->types[] = EmployeeElement::class;
            }
        );

        // Register our fields
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = VoucherField::class;
                $event->types[] = EmployeeField::class;
            }
        );

        // Register our variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('businessToBusiness', BusinessToBusinessVariable::class);
            }
        );

        // Do something after we're installed
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    // We were just installed
                }
            }
        );

        Event::on(
            ShippingMethods::class,
            ShippingMethods::EVENT_REGISTER_AVAILABLE_SHIPPING_METHODS, function(RegisterAvailableShippingMethodsEvent $e)
            {
                
            });

        // Event::on(
        //     Users::class,
        //     Users::EVENT_AFTER_ASSIGN_USER_TO_DEFAULT_GROUP,
        //     function(UserAssignGroupEvent $event) {
        //         $userId = $event->user->id;
                
            
        //         // $entry = Craft::$app->getElements()->getElementById($entryId, Product::class);
        //         // $subject = "Business Account Update";
        //         // $user = Craft::$app->users->getUserById($userId);
        //         // $html = "Your employee " . $user->firstName . " " . $user->lastName . " Employee ID/Username(" . $user->username . ") has signed up for your business account";
        //         // $mail = $entry['newSignUpAlert'];
        //         // if($mail != '')
        //         // {
        //         //     $this->sendMail($html, $subject, $mail);
        //         // }
        //     }
        // );
        
        //checks if any voucher shoes are in the cart
        Event::on(Discounts::class, Discounts::EVENT_BEFORE_MATCH_LINE_ITEM, function(MatchLineItemEvent $e) {
                
            
            // $e defaults to true
            // if($e->lineItem->options['purchasedWithVoucher'] == 'yes')
            // {
            //     $e->isValid = false;
            //     $user = Craft::$app->getUser()->getIdentity();
            //     $employee = BusinessToBusiness::$plugin->employee->getEmployeeByUserId($user->id);
            //         if($employee){
            //             // if user has a voucher available the value to be set to true
            //             // if($user['voucherAvailable'] == 'yes')
            //             // {
            //                 $positive = abs($e->discount->baseDiscount);
            //                 // if user has applied a voucher that is valued more than the shoe
            //                 if($e->lineItem->total - $positive >= 0)
            //                 {
            //                     $e->isValid = true;
            //                 }
            //             // }
            //         }
            // }
            
       });

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            return $this->_permissions($event);
        });

        Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, function(Event $e) {
            $currentSite = Craft::$app->getSites()->currentSite->handle;
            if($currentSite == 'business'){
                $user = Craft::$app->getUser()->getIdentity();
                $employee = BusinessToBusiness::$plugin->employee->getCurrentEmployee($user->id);
                $timesVoucherUsed = $employee['timesVoucherUsed'];
                $employee['timesVoucherUsed'] = $timesVoucherUsed + 1;
                $employee['dateVoucherUsed'] = date(DateTime::W3C);
                Craft::$app->getElements()->saveElement($employee);
            }
        });

        Event::on(Order::class, Order::EVENT_BEFORE_COMPLETE_ORDER, function(Event $e) {
            // @var Order $order
            $order = $e->sender;
            $shippingMethod = $order->getShippingMethod();
            if($shippingMethod->id != 5)
            {
                $shippingAddress = $order->getShippingAddress();
                $order->setFieldValue('streetSpecialOrder', $shippingAddress->address1);
                $order->setFieldValue('citySpecialOrder', $shippingAddress->city);
                $order->setFieldValue('stateSpecialOrder', $shippingAddress->stateName);
                $order->setFieldValue('zipcodeSpecialOrder', $shippingAddress->zipCode);
            }
            $order->setFieldValue('paidSpecialOrder', $order->paidStatus);
        });
        

        Event::on(OrderStatuses::class, OrderStatuses::EVENT_DEFAULT_ORDER_STATUS, function(DefaultOrderStatusEvent $e) {
            
            $currentSite = Craft::$app->getSites()->currentSite->handle;
            // $user = Craft::$app->getUser();
            
            if($currentSite == 'business'){
                $user = Craft::$app->getUser()->getIdentity();
                $employee = BusinessToBusiness::$plugin->employee->getCurrentEmployee($user->id);
                if($employee->authorized == 1){
                
                        $e->orderStatus->id = 9;

                } else if($employee->authorized == 0) {
 
                        $e->orderStatus->id = 10;

                }
                $employee->voucherAvailable = NULL;
                Craft::$app->elements->saveElement($employee);
            }
        });

        // Event::on(Transactions::class, Transactions::EVENT_AFTER_SAVE_TRANSACTION, function(TransactionEvent $e) {
        //         $e->status = STATUS_PENDING;
        // });


        // // Nav Items
        // Event::on(
        //     Cp::class,
        //     Cp::EVENT_REGISTER_CP_NAV_ITEMS,
        //     function(RegisterCpNavItemsEvent $event) {
        //         $event->navItems[] = [
        //             'url' => 'business',
        //             'label' => 'Business',
        //             'subnav' => [
        //                 'business' => ['label' => 'Business', 'url' => 'business-to-business/business'],
        //                 'voucher' => ['label' => 'Voucher', 'url' => 'business-to-business/voucher'],
        //                 'settings' => ['label' => 'Settings', 'url' => 'business-to-business/settings'],
        //             ],
        //         ];
        //     }
        // );

/**
 * Logging in Craft involves using one of the following methods:
 *
 * Craft::trace(): record a message to trace how a piece of code runs. This is mainly for development use.
 * Craft::info(): record a message that conveys some useful information.
 * Craft::warning(): record a warning message that indicates something unexpected has happened.
 * Craft::error(): record a fatal error that should be investigated as soon as possible.
 *
 * Unless `devMode` is on, only Craft::warning() & Craft::error() will log to `craft/storage/logs/web.log`
 *
 * It's recommended that you pass in the magic constant `__METHOD__` as the second parameter, which sets
 * the category to the method (prefixed with the fully qualified class name) where the constant appears.
 *
 * To enable the Yii debug toolbar, go to your user account in the AdminCP and check the
 * [] Show the debug toolbar on the front end & [] Show the debug toolbar on the Control Panel
 *
 * http://www.yiiframework.com/doc-2.0/guide-runtime-logging.html
 */
        Craft::info(
            Craft::t(
                'business-to-business',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return \craft\base\Model|null
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }

    public function getSettingsUrl(): bool
    {
        return false;
    }

    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     */
    protected function settingsHtml(): string
    {
        return Craft::$app->view->renderTemplate(
            'business-to-business/settings/general',
            [
                'settings' => $this->getSettings()
            ]
        );
    }

    public function getSettingsResponse()
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('business-to-business/settings/general'));
    }

    // public function getCpNavItem()
    // {
    //     $item = parent::getCpNavItem();
    //     // $item['badgeCount'] = 5;
    public function getCpNavItem(): array
    {
        $navItems = parent::getCpNavItem();

        if (Craft::$app->getUser()->getIsAdmin()) {
            $navItems['subnav']['business'] = [
                'label' => Craft::t('business-to-business', 'Business'),
                'url' => 'business-to-business/business',
            ];
        }

        if (Craft::$app->getUser()->checkPermission('accessPlugin-business-to-business')) {
            $navItems['subnav']['vouchers'] = [
                'label' => Craft::t('business-to-business', 'Vouchers'),
                'url' => 'business-to-business/vouchers',
            ];
        }

        if (Craft::$app->getUser()->checkPermission('accessPlugin-business-to-business')) {
            $navItems['subnav']['employees'] = [
                'label' => Craft::t('business-to-business', 'Employees'),
                'url' => 'business-to-business/employees',
            ];
        }

        if (Craft::$app->getUser()->checkPermission('accessPlugin-business-to-business')) {
            $navItems['subnav']['orders'] = [
                'label' => Craft::t('business-to-business', 'Orders'),
                'url' => 'business-to-business/orders/index',
            ];
        }
        
        if (Craft::$app->getUser()->getIsAdmin()) {
            $navItems['subnav']['settings'] = [
                'label' => Craft::t('business-to-business', 'Settings'),
                'url' => 'business-to-business/settings',
            ];
        }

        return $navItems;
    }
    //     $item['subnav'] = [
    //         'business' => ['label' => 'Business', 'url' => 'business-to-business/business'],
    //         'vouchers' => ['label' => 'Vouchers', 'url' => 'business-to-business/vouchers'],
    //         'employees' => ['label' => 'Employees', 'url' => 'business-to-business/employees'],
    //         'orders' => ['label' => 'Orders', 'url' => 'business-to-business/orders'],
    //         // 'settings' => ['label' => 'Settings', 'url' => 'business-to-business/settings/general'],
    //     ];
    //     return $item;
    // }

private function _registerEventHandlers()
{
    // Event::on(Sites::class, Sites::EVENT_AFTER_SAVE_SITE, [$this->getBusinesses(), 'afterSaveSiteHandler']);
    // Event::on(Sites::class, Sites::EVENT_AFTER_SAVE_SITE, [$this->getVouchers(), 'afterSaveSiteHandler']);
    // Event::on(Order::class, Order::EVENT_BEFORE_COMPLETE_ORDER, [$this->getCodes(), 'handleCompletedOrder']);
}

private function _permissions($event)
{
    // $businesses = $this->getBusiness()->getAllBusinesses();
    $businesses = $this->business->getAllBusinesses();

        $businessPermissions = [];
        foreach ($businesses as $id => $business) {
            $suffix = ':' . $id;
            $businessPermissions['businessToBusiness-manageBusiness' . $suffix] = ['label' => Craft::t('business-to-business', 'Manage {type}', ['type' => $business->id])];
        }
        foreach ($businesses as $id => $business) {
            $suffix = ':' . $id;
            // $businessPermissions['businessToBusiness-manageBusiness' . $suffix] = ['label' => Craft::t('business-to-business', 'Manage {type}', ['type' => $business->name])];
            $event->permissions[Craft::t('business-to-business', 'Business To Business - ' . $business->name)] = [
                'businessToBusiness-manageBusiness' . $suffix => ['label' => Craft::t('business-to-business', 'Manage business'),
                    'nested' => [
                        'businessToBusiness-manageVouchers' . $suffix => [
                        'label' => Craft::t('business-to-business', 'Create/Edit vouchers')
                        ],
                        'businessToBusiness-deleteVouchers' . $suffix => [
                        'label' => Craft::t('business-to-business', 'Delete vouchers')
                        ],
                        'businessToBusiness-manageEmployees' . $suffix => [
                        'label' => Craft::t('business-to-business', 'Manage Employees')
                        ],
                        'businessToBusiness-deleteEmployees' . $suffix => [
                        'label' => Craft::t('business-to-business', 'Delete Employees')
                        ],
                    ],
                ],
            ];
        }
    }
}
