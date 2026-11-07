<?php

// Isolated framework contract doubles; this does not claim genuine native-store execution.

namespace Magento\Customer\CustomerData { interface SectionSourceInterface
{
    public function getSectionData();
} }

namespace Magento\Framework\App\Config { interface ScopeConfigInterface
{
    public function isSetFlag($key, $scope, $id);

    public function getValue($key, $scope, $id);
} }

namespace Magento\Framework\Encryption { interface EncryptorInterface
{
    public function decrypt($value);
} }

namespace Magento\Store\Model {
    interface ScopeInterface
    {
        public const SCOPE_STORE = 'store';
    }
    interface StoreManagerInterface
    {
        public function getStore();
    }
}

namespace Magento\Checkout\Model {
    class Session
    {
        public $quote;

        public function getQuote()
        {
            return $this->quote;
        }
    }
}

namespace Magento\Framework\View\Element\Template { class Context
{
    public $stores;

    public $action = 'catalog_product_view';
} }

namespace Magento\Framework\View\Element {
    class Template
    {
        protected $_storeManager;

        public function __construct(protected $context, array $data = [])
        {
            $this->_storeManager = $context->stores;
        }

        public function getRequest()
        {
            return new class($this->context)
            {
                public function __construct(private $c) {}

                public function getFullActionName()
                {
                    return $this->c->action;
                }
            };
        }

        public function getUrl($route, $args)
        {
            if (($args['_nosid'] ?? null) !== true) {
                throw new \RuntimeException('SID must be excluded');
            }

            return 'https://shop.example.com/customer/section/load/?sections=shopbonus_cart';
        }

        public function toHtml()
        {
            return $this->_toHtml();
        }
    }
}

namespace Opencart\System\Engine {
    class Controller
    {
        public $config;

        public $request;

        public $response;

        public $session;

        public $cart;

        public $currency;

        public $load;

        public $customer;

        public $model_checkout_cart;

        public $url;
    }
}

namespace {
    use Magento\Checkout\Model\Session;
    use Magento\Framework\App\Config\ScopeConfigInterface;
    use Magento\Framework\Encryption\EncryptorInterface;
    use Magento\Framework\View\Element\Template\Context;
    use Magento\Store\Model\StoreManagerInterface;
    use Shopbonus\AsyncBridge\Block\Banner;
    use Shopbonus\AsyncBridge\Model\Config;

    $checks = 0;
    function check($condition)
    {
        $GLOBALS['checks']++;
        if (! $condition) {
            throw new RuntimeException('Contract check '.$GLOBALS['checks'].' failed');
        }
    }
    $root = dirname(__DIR__);
    $platform = $argv[1] ?? 'common';
    $temp = sys_get_temp_dir().'/sb-cart-fixture-'.bin2hex(random_bytes(8));
    mkdir($temp, 0700);
    try {
        if ($platform === 'common') {
            require $root.'/merchant-packages/common/CartSnapshot.php';
            check(ShopbonusCartSnapshot::fromMajor('94.00', 'EUR', 2, 'prestashop')['total_minor'] === 9400);
            check(ShopbonusCartSnapshot::fromMajor(0, 'EUR', 2, 'magento')['total_minor'] === 0);
            foreach (['-1', '94,00', '94.001', '1e2', INF, NAN, null, true] as $value) {
                check(ShopbonusCartSnapshot::fromMajor($value, 'EUR', 2, 'opencart') === null);
            }
            foreach ([['JPY', 0], ['KWD', 3], ['AAA', 2], ['EUR', '2']] as [$currency, $precision]) {
                check(ShopbonusCartSnapshot::fromMajor(94, $currency, $precision, 'prestashop') === null);
            }
        } elseif ($platform === 'prestashop') {
            $zip = new ZipArchive;
            $zip->open($root.'/public/downloads/integrations/shopbonus-prestashop9.zip');
            $zip->extractTo($temp);
            $zip->close();
            define('_PS_VERSION_', '9.0.0');
            class Module
            {
                public $name;

                public $tab;

                public $version;

                public $author;

                public $bootstrap;

                public $ps_versions_compliancy;

                public $displayName;

                public $description;

                public $context;

                public $active = true;

                public function __construct() {}
            }
            class Configuration
            {
                public static $enabled = '1';

                public static function get($key, ...$args)
                {
                    return ['SB_BANNER' => self::$enabled, 'SB_ORIGIN' => 'https://api.example.com', 'SB_KEY' => 'pk_SYNTHETIC'][$key] ?? '';
                }
            }
            class Group
            {
                public static $visible = true;

                public static function getCurrent()
                {
                    return (object) ['show_prices' => self::$visible];
                }
            }
            class Cart
            {
                public const BOTH = 3;

                public $id = 1;

                public $id_shop = 2;

                public $id_currency = 3;

                public $amount = 94.0;

                public function getOrderTotal($tax, $type)
                {
                    check($tax === true && $type === self::BOTH);

                    return $this->amount;
                }
            }
            require $temp.'/shopbonus/shopbonus.php';
            $module = new Shopbonus;
            $module->context = (object) ['shop' => (object) ['id' => 2], 'cart' => new Cart, 'cookie' => (object) ['id_cart' => 1], 'currency' => (object) ['id' => 3, 'iso_code' => 'EUR', 'precision' => 2],
                'controller' => (object) ['php_self' => 'product'], 'link' => new class
                {
                    public function getModuleLink(...$args)
                    {
                        return 'https://shop.example.com/module/shopbonus/cart';
                    }
                }];
            check($module->cartSnapshot()['total_minor'] === 9400);
            check(! array_key_exists('id_cart', $module->cartSnapshot()));
            $html = $module->hookDisplayFooter();
            check(strpos($html, 'native-cart-v1.js') < strpos($html, '/embed/v1.js'));
            check(str_contains($html, 'data-platform="prestashop"'));
            $module->context->controller->php_self = 'order';
            check($module->hookDisplayFooter() === '');
            $module->context->cart->id_shop = 77;
            check($module->cartSnapshot() === null);
            $module->context->cart->id_shop = 2;
            $module->context->currency->id = 4;
            check($module->cartSnapshot() === null);
            $module->context->currency->id = 3;
            $module->context->cart->amount = 94.001;
            check($module->cartSnapshot() === null);
            $module->context->cart->amount = 94;
            $module->context->cookie->id_cart = 77;
            check($module->cartSnapshot() === null);
            $module->context->cookie->id_cart = 1;
            Group::$visible = false;
            check($module->cartSnapshot() === null);
            Group::$visible = true;
            Configuration::$enabled = '0';
            check($module->cartSnapshot() === null);
        } elseif ($platform === 'opencart') {
            mkdir($temp.'/shopbonus');
            $zip = new ZipArchive;
            $zip->open($root.'/public/downloads/integrations/shopbonus.ocmod.zip');
            $zip->extractTo($temp.'/shopbonus');
            $zip->close();
            define('DIR_EXTENSION', $temp.'/');
            require $temp.'/shopbonus/catalog/controller/module/shopbonus.php';
            $c = new Opencart\Catalog\Controller\Extension\Shopbonus\Module\Shopbonus;
            $c->config = new class
            {
                public $values = ['module_shopbonus_status' => true, 'module_shopbonus_banner' => true, 'config_store_id' => 0, 'config_customer_price' => false, 'module_shopbonus_origin' => 'https://api.example.com', 'module_shopbonus_key' => 'pk_SYNTHETIC', 'config_language' => 'en-gb'];

                public function get($key)
                {
                    return $this->values[$key] ?? null;
                }
            };
            $c->request = (object) ['server' => ['REQUEST_METHOD' => 'GET'], 'get' => ['route' => 'product/product']];
            $c->response = new class
            {
                public $headers = [];

                public function addHeader($v)
                {
                    $this->headers[] = $v;
                }
            };
            $c->session = (object) ['data' => ['currency' => 'EUR']];
            $c->cart = new class
            {
                public function getTaxes()
                {
                    return [];
                }
            };
            $c->customer = new class
            {
                public function isLogged()
                {
                    return false;
                }
            };
            $c->currency = new class
            {
                public function has($code)
                {
                    return true;
                }

                public function getDecimalPlace($code)
                {
                    return 2;
                }

                public function format($total, $code, $value, $formatted)
                {
                    check($total === 47 && $formatted === false);

                    return 94.0;
                }
            };
            $c->load = new class
            {
                public function model($name) {}
            };
            $c->model_checkout_cart = (object) ['getTotals' => function (&$totals, &$taxes, &$total) {
                $total = 47;
            }];
            $c->url = new class
            {
                public function link(...$args)
                {
                    return 'https://shop.example.com/index.php?route=common/cart.info&language=en-gb';
                }
            };
            $route = 'common/cart.info';
            $args = [];
            $out = 'NATIVE HTML UNCHANGED';
            $c->cartSnapshot($route, $args, $out);
            $headers = $c->response->headers;
            $header = array_values(array_filter($headers, fn ($h) => str_starts_with($h, 'X-ShopBonus-Cart: ')))[0];
            check(json_decode(substr($header, strlen('X-ShopBonus-Cart: ')), true)['total_minor'] === 9400); // native presentment conversion, not base47
            check(in_array('Cache-Control: private, no-store, max-age=0', $headers, true));
            check($out === 'NATIVE HTML UNCHANGED');
            $c->banner($route, $args, $out);
            check(strpos($out, 'native-cart-v1.js') < strpos($out, '/embed/v1.js'));
            $c->request->get['route'] = 'checkout/checkout';
            $out = 'UNCHANGED';
            $c->banner($route, $args, $out);
            check($out === 'UNCHANGED');
            foreach ([['config_store_id', 2], ['module_shopbonus_banner', false], ['config_customer_price', true]] as [$key, $value]) {
                $old = $c->config->values[$key];
                $c->config->values[$key] = $value;
                $c->response->headers = [];
                $c->cartSnapshot($route, $args, $out);
                check($c->response->headers === []);
                $c->config->values[$key] = $old;
            }
        } elseif ($platform === 'magento') {
            $zip = new ZipArchive;
            $zip->open($root.'/public/downloads/integrations/shopbonus-magento2-async.zip');
            $zip->extractTo($temp);
            $zip->close();
            require $temp.'/Model/Config.php';
            require $temp.'/CustomerData/Cart.php';
            require $temp.'/Block/Banner.php';
            $scope = new class implements ScopeConfigInterface
            {
                public $enabled = true;

                public function isSetFlag($key, $scope, $id)
                {
                    return $this->enabled;
                }

                public function getValue($key, $scope, $id)
                {
                    return str_ends_with($key, 'banner_origin') ? 'https://api.example.com' : 'pk_SYNTHETIC';
                }
            };
            $config = new Config($scope, new class implements EncryptorInterface
            {
                public function decrypt($v)
                {
                    return $v;
                }
            });
            $store = new class
            {
                public function getId()
                {
                    return 2;
                }

                public function getCurrentCurrencyCode()
                {
                    return 'EUR';
                }
            };
            $stores = new class($store) implements StoreManagerInterface
            {
                public function __construct(private $s) {}

                public function getStore()
                {
                    return $this->s;
                }
            };
            $session = new Session;
            $session->quote = new class
            {
                public $store = 2;

                public $currency = 'EUR';

                public $amount = '94.00';

                public function getId()
                {
                    return 1;
                }

                public function getIsActive()
                {
                    return true;
                }

                public function getStoreId()
                {
                    return $this->store;
                }

                public function getQuoteCurrencyCode()
                {
                    return $this->currency;
                }

                public function getTotals()
                {
                    return ['grand_total' => new class($this->amount)
                    {
                        public function __construct(private $value) {}

                        public function getValue()
                        {
                            return $this->value;
                        }
                    }];
                }
            };
            $section = new Shopbonus\AsyncBridge\CustomerData\Cart($session, $stores, $config);
            check($section->getSectionData()['total_minor'] === 9400);
            check(! array_key_exists('quote_id', $section->getSectionData()));
            $session->quote->store = 7;
            check($section->getSectionData() === []);
            $session->quote->store = 2;
            $session->quote->currency = 'USD';
            check($section->getSectionData() === []);
            $session->quote->currency = 'EUR';
            $ctx = new Context;
            $ctx->stores = $stores;
            $block = new Banner($ctx, $config);
            $html = $block->toHtml();
            check(strpos($html, 'native-cart-v1.js') < strpos($html, '/embed/v1.js'));
            check(! str_contains($html, '9400'));
            $ctx->action = 'checkout_index_index';
            check($block->toHtml() === '');
            $scope->enabled = false;
            check($section->getSectionData() === []);
            check($block->toHtml() === '');
        } else {
            throw new RuntimeException('Unknown fixture');
        }
        echo json_encode(['platform' => $platform, 'checks' => $checks], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($temp);
    }
}
