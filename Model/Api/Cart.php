<?php
/**
 * mc-magento2 Magento Component
 *
 * @category Ebizmarts
 * @package mc-magento2
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @date: 3/7/17 12:42 PM
 * @file: Cart.php
 */
namespace Ebizmarts\MailChimp\Model\Api;

use Symfony\Component\Config\Definition\Exception\Exception;

class Cart
{

    const BATCH_LIMIT = 100;

    protected $_firstDate;
    protected $_counter;
    protected $_batchId;
    protected $_api = null;
    protected $_token = null;

    /**
     * @var \Ebizmarts\MailChimp\Helper\Data
     */
    protected $_helper;
    /**
     * @var \Magento\Quote\Model\ResourceModel\Quote\CollectionFactory
     */
    protected $_quoteCollection;
    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $_date;
    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    protected $_customerFactory;
    /**
     * @var Product
     */
    protected $_apiProduct;
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    protected $_orderCollectionFactory;
    /**
     * @var Customer
     */
    protected $_apiCustomer;
    /**
     * @var \Magento\Directory\Api\CountryInformationAcquirerInterface
     */
    protected $_countryInformation;
    /**
     * @var \Magento\Framework\Url
     */
    protected $_urlHelper;

    /**
     * Cart constructor.
     * @param \Ebizmarts\MailChimp\Helper\Data $helper
     * @param \Magento\Quote\Model\ResourceModel\Quote\CollectionFactory $quoteColletcion
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param \Magento\Customer\Model\CustomerFactory $customerFactory
     * @param Product $apiProduct
     * @param Customer $apiCustomer
     * @param \Magento\Directory\Api\CountryInformationAcquirerInterface $countryInformation
     * @param \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory
     * @param \Magento\Framework\Url $urlHelper
     */
    public function __construct(
        \Ebizmarts\MailChimp\Helper\Data $helper,
        \Magento\Quote\Model\ResourceModel\Quote\CollectionFactory $quoteColletcion,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Ebizmarts\MailChimp\Model\Api\Product  $apiProduct,
        \Ebizmarts\MailChimp\Model\Api\Customer $apiCustomer,
        \Magento\Directory\Api\CountryInformationAcquirerInterface $countryInformation,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Framework\Url $urlHelper
    )
    {
        $this->_helper                  = $helper;
        $this->_quoteCollection         = $quoteColletcion;
        $this->_date                    = $date;
        $this->_customerFactory         = $customerFactory;
        $this->_apiProduct              = $apiProduct;
        $this->_apiCustomer             = $apiCustomer;
        $this->_orderCollectionFactory  = $orderCollectionFactory;
        $this->_countryInformation      = $countryInformation;
        $this->_urlHelper               = $urlHelper;
    }

        /**
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     * @return array
     */
    public function createBatchJson($magentoStoreId)
    {
        $this->_helper->log(__METHOD__);
        $allCarts = array();
        if (!$this->_helper->getConfigValue(\Ebizmarts\MailChimp\Helper\Data::XML_ABANDONEDCART_ACTIVE,$magentoStoreId)) {
            return $allCarts;
        }

        $this->_firstDate = $this->_helper->getConfigValue(
            \Ebizmarts\MailChimp\Helper\Data::XML_ABANDONEDCART_FIRSTDATE,
            $magentoStoreId
        );
        $this->_counter = 0;
        $mailchimpStoreId = $this->_helper->getConfigValue(\Ebizmarts\MailChimp\Helper\Data::XML_PATH_STORE,$magentoStoreId);

        $date = $this->_helper->getDateMicrotime();
        $this->_batchId = 'storeid-' . $magentoStoreId . '_' . \Ebizmarts\MailChimp\Helper\Data::IS_QUOTE.'_'.$date;
        // get all the carts converted in orders (must be deleted on mailchimp)
        $allCarts = array_merge($allCarts, $this->_getConvertedQuotes($mailchimpStoreId, $magentoStoreId));
        // get all the carts modified but not converted in orders
        $allCarts = array_merge($allCarts, $this->_getModifiedQuotes($mailchimpStoreId, $magentoStoreId));
        // get new carts
        $allCarts = array_merge($allCarts, $this->_getNewQuotes($mailchimpStoreId, $magentoStoreId));
        return $allCarts;
    }

    /**
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     * @return array
     */
    protected function _getConvertedQuotes($mailchimpStoreId, $magentoStoreId)
    {
        $this->_helper->log(__METHOD__);
        $allCarts = array();
        $convertedCarts = $this->_getQuoteCollection();
        // get only the converted quotes
        $convertedCarts->addFieldToFilter('store_id', array('eq' => $magentoStoreId));
        $convertedCarts->addFieldToFilter('is_active', array('eq' => 0));
        //join with mailchimp_ecommerce_sync_data table to filter by sync data.
        $convertedCarts->getSelect()->joinLeft(
            ['m4m' => 'mailchimp_sync_ecommerce'],
            "m4m.related_id = main_table.entity_id and m4m.type = '".\Ebizmarts\MailChimp\Helper\Data::IS_QUOTE."'
            AND m4m.mailchimp_store_id = '" . $mailchimpStoreId . "'",
            ['m4m.*']
        );
        // be sure that the quotes are already in mailchimp and not deleted
        $convertedCarts->getSelect()->where("m4m.mailchimp_sync_deleted = 0");
        // limit the collection
        $convertedCarts->getSelect()->limit(self::BATCH_LIMIT);
        $this->_helper->log((string)$convertedCarts->getSelect());
        /**
         * @var $cart \Magento\Quote\Model\Quote
         */
        foreach ($convertedCarts as $cart) {
            $cartId = $cart->getEntityId();
            // we need to delete all the carts associated with this email
            $allCartsForEmail = $this->_getAllCartsByEmail($cart->getCustomerEmail(), $mailchimpStoreId, $magentoStoreId);
            /**
             * @var $cartForEmail \Magento\Quote\Model\Quote
             */
            foreach ($allCartsForEmail as $cartForEmail) {
                $alreadySentCartId = $cartForEmail->getEntityId();
                if ($alreadySentCartId != $cartId) {
                    $allCarts[$this->_counter]['method'] = 'DELETE';
                    $allCarts[$this->_counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts/' . $alreadySentCartId;
                    $allCarts[$this->_counter]['operation_id'] = $this->_batchId . '_' . $alreadySentCartId;
                    $allCarts[$this->_counter]['body'] = '';
                    $this->_updateQuote($mailchimpStoreId, $alreadySentCartId, $this->_date->gmtDate(), "", 0,1);
                    $this->_counter += 1;
                }
            }

            $allCartsForEmail->clear();
            $allCarts[$this->_counter]['method'] = 'DELETE';
            $allCarts[$this->_counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts/' . $cartId;
            $allCarts[$this->_counter]['operation_id'] = $this->_batchId . '_' . $cartId;
            $allCarts[$this->_counter]['body'] = '';
            $this->_updateQuote($mailchimpStoreId, $cartId, $this->_date->gmtDate(), "", 0,1);
            $this->_counter += 1;
        }

        return $allCarts;
    }

    /**
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     * @return array
     */
    protected function _getModifiedQuotes($mailchimpStoreId, $magentoStoreId)
    {
        $this->_helper->log(__METHOD__);
        $allCarts = array();
        $modifiedCarts = $this->_getQuoteCollection();
        // select carts with no orders
        $modifiedCarts->addFieldToFilter('is_active', array('eq'=>1));
        // select carts for the current Magento store id
        $modifiedCarts->addFieldToFilter('store_id', array('eq' => $magentoStoreId));
        //join with mailchimp_ecommerce_sync_data table to filter by sync data.
        $modifiedCarts->getSelect()->joinLeft(
            ['m4m' => 'mailchimp_sync_ecommerce'],
            "m4m.related_id = main_table.entity_id and m4m.type = '".\Ebizmarts\MailChimp\Helper\Data::IS_QUOTE."'
            AND m4m.mailchimp_store_id = '" . $mailchimpStoreId . "'",
            ['m4m.*']
        );
        // be sure that the quotes are already in mailchimp and not deleted
        $modifiedCarts->getSelect()->where("m4m.mailchimp_sync_modified = 1 AND m4m.mailchimp_sync_deleted = 0".
            " AND m4m.mailchimp_sync_delta < main_table.updated_at");
        // limit the collection
        $modifiedCarts->getSelect()->limit(self::BATCH_LIMIT);
        $this->_helper->log((string)$modifiedCarts->getSelect());
        /**
         * @var $cart \Magento\Quote\Model\Quote
         */
        foreach ($modifiedCarts as $cart) {
            $cartId = $cart->getEntityId();
            $allCarts[$this->_counter]['method'] = 'DELETE';
            $allCarts[$this->_counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts/' . $cartId;
            $allCarts[$this->_counter]['operation_id'] = $this->_batchId . '_' . $cartId;
            $allCarts[$this->_counter]['body'] = '';
            $this->_counter += 1;
            /**
             * @var $customer \Magento\Customer\Model\Customer
             */
//            $customer = Mage::getModel("customer/customer");
//            $customer->setWebsiteId(Mage::getModel('core/store')->load($magentoStoreId)->getWebsiteId());
//            $customer->loadByEmail($cart->getCustomerEmail());
            $customer = $this->_customerFactory->create();
            $customer->setWebsiteId();
            $customer->loadByEmail($cart->getCustomerEmail());

            if ($customer->getEmail() != $cart->getCustomerEmail()) {
                $allCartsForEmail = $this->_getAllCartsByEmail($cart->getCustomerEmail(), $mailchimpStoreId, $magentoStoreId);
                /**
                 * @var $cartForEmail \Magento\Quote\Model\Quote
                 */
                foreach ($allCartsForEmail as $cartForEmail) {
                    $alreadySentCartId = $cartForEmail->getEntityId();
                    if($alreadySentCartId != $cartId) {
                        $allCarts[$this->_counter]['method'] = 'DELETE';
                        $allCarts[$this->_counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts/' . $alreadySentCartId;
                        $allCarts[$this->_counter]['operation_id'] = $this->_batchId . '_' . $alreadySentCartId;
                        $allCarts[$this->_counter]['body'] = '';
                        $this->_updateQuote($mailchimpStoreId, $alreadySentCartId, $this->_date->gmtDate(), null, null, 1);
                        $this->_counter += 1;
                    }
                }

                $allCartsForEmail->clear();
            }
            // avoid carts abandoned as guests when customer email associated to a registered customer.
            if (!$cart->getCustomerId() && $customer->getEmail()==$cart->getCustomerEmail()) {
                $this->_updateQuote($mailchimpStoreId, $cartId, $this->_date->gmtDate());
                continue;
            }

            // send the products that not already sent
            $productData = $this->_apiProduct->sendQuoteModifiedProduct($cart, $mailchimpStoreId, $magentoStoreId);
            if (count($productData)) {
                foreach($productData as $p) {
                    $allCarts[$this->_counter] = $p;
                    $this->_counter += 1;
                }
            }

            if (count($cart->getAllVisibleItems())) {
                $cartJson = $this->_makeCart($cart, $mailchimpStoreId, $magentoStoreId);
                if ($cartJson!="") {
                    $allCarts[$this->_counter]['method'] = 'POST';
                    $allCarts[$this->_counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts';
                    $allCarts[$this->_counter]['operation_id'] = $this->_batchId . '_' . $cartId;
                    $allCarts[$this->_counter]['body'] = $cartJson;
                    $this->_counter += 1;
                }
            }

            $this->_updateQuote($mailchimpStoreId, $cartId, $this->_date->gmtDate());
        }

        return $allCarts;
    }

    /**
     * @param $mailchimpStoreId
     * @return array
     */
    protected function _getNewQuotes($mailchimpStoreId, $magentoStoreId)
    {
        $this->_helper->log(__METHOD__);
        $allCarts = array();
        $newCarts = $this->_getQuoteCollection();
        $newCarts->addFieldToFilter('is_active', array('eq'=>1));
        $newCarts->addFieldToFilter('customer_email', array('notnull'=>true));
        $newCarts->addFieldToFilter('items_count', array('gt'=>0));
        // select carts for the current Magento store id
        $newCarts->addFieldToFilter('store_id', array('eq' => $magentoStoreId));
        // filter by first date if exists.
        if ($this->_firstDate) {
            $newCarts->addFieldToFilter('created_at', array('gt' => $this->_firstDate));
        }
        //join with mailchimp_ecommerce_sync_data table to filter by sync data.
        $newCarts->getSelect()->joinLeft(
            ['m4m' => 'mailchimp_sync_ecommerce'],
            "m4m.related_id = main_table.entity_id and m4m.type = '" . \Ebizmarts\MailChimp\Helper\Data::IS_QUOTE . "'
            AND m4m.mailchimp_store_id = '" . $mailchimpStoreId . "'",
            ['m4m.*']
        );
        // be sure that the quotes are already in mailchimp and not deleted
        $newCarts->getSelect()->where("m4m.mailchimp_sync_delta IS NULL");
        // limit the collection
        $newCarts->getSelect()->limit(self::BATCH_LIMIT);
        $this->_helper->log((string)$newCarts->getSelect());
        /**
         * @var $cart \Magento\Quote\Model\Quote
         */
        foreach ($newCarts as $cart) {
            $this->_helper->log('each cart');
            $cartId = $cart->getEntityId();
            $orderCollection = $this->_getOrderCollection();
            $orderCollection->addFieldToFilter('main_table.customer_email', array('eq' => $cart->getCustomerEmail()))
                ->addFieldToFilter('main_table.updated_at', array('from' => $cart->getUpdatedAt()));
            //if cart is empty or customer has an order made after the abandonment skip current cart.
            $this->_helper->log((string)$orderCollection->getSelect());
            if (!count($cart->getAllVisibleItems()) || $orderCollection->getSize()) {
                $this->_helper->log("cart is empty, leave");
                $this->_updateQuote($mailchimpStoreId, $cartId, $this->_date->gmtDate());
                continue;
            }
            $this->_helper->log('before get the customer by email');
            $customer = $this->_customerFactory->create();
            $customer->setWebsiteId($magentoStoreId);
            $customer->loadByEmail($cart->getCustomerEmail());
            $this->_helper->log('after get customer by email');

            if ($customer->getEmail() != $cart->getCustomerEmail()) {
                $this->_helper->log('not same email');
                $this->_helper->log('before get all carts');
                $allCartsForEmail = $this->_getAllCartsByEmail($cart->getCustomerEmail(), $mailchimpStoreId, $magentoStoreId);
                foreach ($allCartsForEmail as $cartForEmail) {
                    $alreadySentCartId = $cartForEmail->getEntityId();
                    $allCarts[$this->_counter]['method'] = 'DELETE';
                    $allCarts[$this->_counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts/' . $alreadySentCartId;
                    $allCarts[$this->_counter]['operation_id'] = $this->_batchId . '_' . $alreadySentCartId;
                    $allCarts[$this->_counter]['body'] = '';
                    $this->_updateQuote($mailchimpStoreId, $alreadySentCartId, $this->_date->gmtDate(), null, null, 1);
                    $this->_counter += 1;
                }

                $allCartsForEmail->clear();
            }

            // don't send the carts for guest customers who are registered
            if (!$cart->getCustomerId()&&$customer->getEmail()==$cart->getCustomerEmail()) {
                $this->_updateQuote($mailchimpStoreId, $cartId, $this->_date->gmtDate());
                continue;
            }

            // send the products that not already sent
            $this->_helper->log('before send modified products');
            $productData = $this->_apiProduct->sendQuoteModifiedProduct($cart, $mailchimpStoreId, $magentoStoreId);
            if (count($productData)) {
                foreach($productData as $p) {
                    $allCarts[$this->_counter] = $p;
                    $this->_counter += 1;
                }
            }
            $this->_helper->log('after send modified products');

            $cartJson = $this->_makeCart($cart, $mailchimpStoreId, $magentoStoreId);
            if ($cartJson!="") {
                $allCarts[$this->_counter]['method'] = 'POST';
                $allCarts[$this->_counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts';
                $allCarts[$this->_counter]['operation_id'] = $this->_batchId . '_' . $cartId;
                $allCarts[$this->_counter]['body'] = $cartJson;
                $this->_updateQuote($mailchimpStoreId, $cartId, $this->_date->gmtDate());
                $this->_counter += 1;
            }
        }

        return $allCarts;
    }

    /**
     * Get all existing carts in the current store view for a given email address.
     *
     * @param $email
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     * @return object
     */
    protected function _getAllCartsByEmail($email, $mailchimpStoreId, $magentoStoreId)
    {
        $this->_helper->log(__METHOD__);
        $allCartsForEmail = $this->_getQuoteCollection();
        $allCartsForEmail->addFieldToFilter('is_active', array('eq' => 1));
        $allCartsForEmail->addFieldToFilter('store_id', array('eq' => $magentoStoreId));
        $allCartsForEmail->addFieldToFilter('customer_email', array('eq' => $email));
        $allCartsForEmail->getSelect()->joinLeft(
            ['m4m' => 'mailchimp_sync_ecommerce'],
            "m4m.related_id = main_table.entity_id and m4m.type = '".\Ebizmarts\MailChimp\Helper\Data::IS_QUOTE."'
            AND m4m.mailchimp_store_id = '" . $mailchimpStoreId . "'",
            ['m4m.*']
        );
        // be sure that the quotes are already in mailchimp and not deleted
        $allCartsForEmail->getSelect()->where("m4m.mailchimp_sync_deleted = 0");
        $this->_helper->log((string)$allCartsForEmail->getSelect());
        return $allCartsForEmail;
    }

    /**
     * @param $cart
     * @param $mailchimpStoreId
     * @param $magentoStoreId
     * @return string
     */
    protected function _makeCart(\Magento\Quote\Model\Quote $cart, $mailchimpStoreId, $magentoStoreId)
    {
        $this->_helper->log(__METHOD__);
        $this->_token = null;
        $campaignId = $cart->getMailchimpCampaignId();
        $oneCart = array();
        $oneCart['id'] = $cart->getEntityId();
        $this->_helper->log('before get customer');
        $oneCart['customer'] = $this->_getCustomer($cart, $mailchimpStoreId, $magentoStoreId);
        if ($campaignId) {
            $oneCart['campaign_id'] = $campaignId;
        }
        $this->_helper->log('after get customer');

        $oneCart['checkout_url'] = $this->_getCheckoutUrl($cart);
        $oneCart['currency_code'] = $cart->getQuoteCurrencyCode();
        $oneCart['order_total'] = $cart->getGrandTotal();
        $oneCart['tax_total'] = 0;
        $lines = array();
        // get all items on the cart
        $items = $cart->getAllVisibleItems();
        $itemCount = 0;
        /**
         * @var $item \Magento\Quote\Model\Quote\Item
         */
        foreach ($items as $item) {
            $line = array();
            if ($item->getProductType()=='bundle'||$item->getProductType()=='grouped') {
                continue;
            }

            if ($item->getProductType()==\Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE) {
                $variant = null;
                if ($item->getOptionByCode('simple_product')) {
                    $variant = $item->getOptionByCode('simple_product')->getProduct();
                }

                if (!$variant) {
                    continue;
                }

                $variantId = $variant->getId();
            } else {
                $variantId = $item->getProductId();
            }

            //id can not be 0 so we add 1 to $itemCount before setting the id.
            $itemCount++;
            $line['id'] = (string)$itemCount;
            $line['product_id'] = $item->getProductId();
            $line['product_variant_id'] = $variantId;
            $line['quantity'] = (int)$item->getQty();
            $line['price'] = $item->getPrice();
            $lines[] = $line;
        }

        $jsonData = "";
        if ($itemCount) {
            $oneCart['lines'] = $lines;
            //enconde to JSON
            try {
                $jsonData = json_encode($oneCart);
            } catch (Exception $e) {
                //json encode failed
               $this->_helper->log("Carts " . $cart->getId() . " json encode failed");
            }
        }

        return $jsonData;
    }
    /**
     * @param \Magento\Quote\Model\Quote $cart
     * @return string
     */
    protected function _getCheckoutUrl(\Magento\Quote\Model\Quote $cart)
    {
        $token = md5(rand(0, 9999999));
        $url = $this->_urlHelper->getUrl(
            'mailchimp/cart/loadquote', array(
                'id' => $cart->getId(),
                'token' => $token,
                '_nosid' => true,
                '_secure' => true
            )
        );
        $this->_token = $token;
        return $url;
    }
    protected function _getCustomer(\Magento\Quote\Model\Quote $cart,$mailchimpStoreId, $magentoStoreId)
    {
        $api = $this->_helper->getApi($magentoStoreId);
        $customers = array();
        try {
            $customers = $api->ecommerce->customers->getByEmail($mailchimpStoreId, $cart->getCustomerEmail());
        } catch (\Mailchimp_Error $e) {
            $this->_helper->log($e->getMessage());
        }

        if (isset($customers['total_items']) && $customers['total_items'] > 0) {
            $customer = array(
                'id' => $customers['customers'][0]['id']
            );
        } else {
            if (!$cart->getCustomerId()) {
                $date = $this->_helper->getDateMicrotime();
                $customer = array(
                    "id" => "GUEST-" . $date,
                    "email_address" => $cart->getCustomerEmail(),
                    "opt_in_status" => false
                );
            } else {
                $customer = array(
                    "id" => $cart->getCustomerId(),
                    "email_address" => $cart->getCustomerEmail(),
                    "opt_in_status" => $this->_apiCustomer->getOptin($magentoStoreId)
                );
            }
        }

        $firstName = $cart->getCustomerFirstname();
        if ($firstName) {
            $customer["first_name"] = $firstName;
        }

        $lastName = $cart->getCustomerLastname();
        if ($lastName) {
            $customer["last_name"] = $lastName;
        }

        $billingAddress = $cart->getBillingAddress();
        if ($billingAddress) {
            $street = $billingAddress->getStreet();
            $address = array();
            if ($street[0]) {
                $address['address1'] = $street[0];
            }

            if (count($street) > 1) {
                $address['address1'] = $street[1];
            }

            if ($billingAddress->getCity()) {
                $address['city'] = $billingAddress->getCity();
            }

            if ($billingAddress->getRegion()) {
                $address['province'] = $billingAddress->getRegion();
            }

            if ($billingAddress->getRegionCode()) {
                $address['province_code'] = $billingAddress->getRegionCode();
            }

            if ($billingAddress->getPostcode()) {
                $address['postal_code'] = $billingAddress->getPostcode();
            }

            if ($billingAddress->getCountryId()) {
                $country = $this->_countryInformation->getCountryInfo($billingAddress->getCountryId());
                $countryName = $country->getFullNameLocale();
                $address['shipping_address']['country'] = $countryName;
                $address['shipping_address']['country_code'] = $country->getTwoLetterAbbreviation();
            }

            if (count($address)) {
                $customer['address'] = $address;
            }
        }

        //company
//        if ($billingAddress->getCompany()) {
//            $customer["company"] = $billingAddress->getCompany();
//        }

        return $customer;
    }

    /**
     * @return \Magento\Quote\Model\ResourceModel\Quote\Collection
     */
    protected function _getQuoteCollection()
    {
        return $this->_quoteCollection->create();
    }

    /**
     * @return \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    protected function _getOrderCollection()
    {
        return $this->_orderCollectionFactory->create();
    }

    /**
     * @param $storeId
     * @param $entityId
     * @param $sync_delta
     * @param $sync_error
     * @param $sync_modified
     * @param $sync_deleted
     */
    protected function _updateQuote($storeId, $entityId, $sync_delta, $sync_error='', $sync_modified=0, $sync_deleted=0)
    {
        $this->_helper->log(__METHOD__);
        $this->_helper->saveEcommerceData($storeId, $entityId, $sync_delta, $sync_error, $sync_modified,
            \Ebizmarts\MailChimp\Helper\Data::IS_QUOTE, $sync_deleted, $this->_token);
    }
}