<?php

declare(strict_types=1);

namespace Zero1\OpenPosSplitPayments\Magewire;

use Magewirephp\Magewire\Component;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Payment\Helper\Data as PaymentHelper;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;

class SplitMethod extends Component implements EvaluationInterface
{
    const OPENPOS_SPLIT_PAYMENT_METHOD_CODE = 'openpos_split_payment';

    public $loader = 'Loading available payment methods...';

    public $listeners = [
        'save'
    ];

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var PricingHelper
     */
    protected $pricingHelper;

    /**
     * @var PaymentHelper
     */
    protected $paymentHelper;

    /**
     * @var array
     */
    public $paymentMethods = [];

    public $applied = false;

    public $ignoreOutstandingBalance = false;

    /**
     * @param CheckoutSession $checkoutSession
     * @param PricingHelper $pricingHelper
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(
        CheckoutSession $checkoutSession,
        PricingHelper $pricingHelper,
        PaymentHelper $paymentHelper
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->pricingHelper = $pricingHelper;
        $this->paymentHelper = $paymentHelper;
    }

    public function mount(): void
    {
        $this->getPaymentMethods();
        parent::mount();
    }

    /**
     * Retrieve a list of payment methods available for use as a split payment method.
     *
     * @return void
     */
    public function getPaymentMethods(): void
    {
        $paymentMethods = $this->paymentHelper->getPaymentMethods();

        foreach($paymentMethods as $code => $paymentMethod) {
            if(strpos($code, 'openpos') === false) {
                continue;
            }
            if($code === self::OPENPOS_SPLIT_PAYMENT_METHOD_CODE) {
                continue;
            }

            if(!isset($this->paymentMethods[$code])) {
                $this->paymentMethods[$code] = [
                    'code' => $code,
                    'title' => $paymentMethod['title'],
                    'inUse' => false,
                    'amount' => 0
                ];
            }
        }
    }

    /**
     * Save amount tendered to quote.
     *
     * @return void
     */
    public function save(): void
    {
        $this->applied = true;
//
//
//        if($this->amountTendered && !is_numeric($this->amountTendered)) {
//            $this->('Amount entered is not valid!');
//            $this->amountTendered = 0;
//            return;
//        }

        $paymentData = [];
        foreach($this->paymentMethods as $code => $paymentMethod) {
            if($paymentMethod['inUse']) {
                $paymentData[] = [
                    'title' => $paymentMethod['title'],
                    'amount' => $paymentMethod['amount']
                ];
            }
        }

        $payment = $this->checkoutSession->getQuote()->getPayment();
        $payment->setAdditionalInformation('split_payment_data', json_encode($paymentData));
        $this->checkoutSession->getQuote()->save();
    }

    public function getTotalRemaining()
    {
        $totalAmount = 0;
        foreach($this->paymentMethods as $paymentMethod) {
            if($paymentMethod['inUse']) {
                $totalAmount += $paymentMethod['amount'];
            }
        }

        return $this->pricingHelper->currency(($this->checkoutSession->getQuote()->getGrandTotal() - $totalAmount), true);
    }

    public function evaluateCompletion(EvaluationResultFactory $factory): EvaluationResultInterface
    {
        if(!$this->applied) {
            return $factory->createErrorMessage((string) __('Cannot place order. You must apply split payment changes.'));
        }

        $methodInUse = false;
        $totalAmount = 0;
        foreach($this->paymentMethods as $paymentMethod) {
            if($paymentMethod['inUse']) {
                $methodInUse = true;
                $totalAmount += $paymentMethod['amount'];
            }
            if($paymentMethod['inUse'] && $paymentMethod['amount'] < 0) {
                return $factory->createErrorMessage((string) __('Cannot place order. Payment method : '.$paymentMethod['title'].' is in use but has an invalid amount.'));
            }
        }

        if(!$methodInUse) {
            return $factory->createErrorMessage((string) __('Cannot place order. You must select one method to use within split payments.'));
        }

        if($totalAmount < $this->checkoutSession->getQuote()->getGrandTotal() && !$this->ignoreOutstandingBalance) {
            return $factory->createErrorMessage((string) __('Cannot place order. The total amount between split payments is less than the cart grand total.'));
        }

        return $factory->createSuccess();
    }
}
