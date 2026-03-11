use Bitrix\Main\EventManager;
use Bitrix\Sale\Order;

class InstallmentOrderHandler
{
    const PAY_SYSTEM_ID = ID_ПЛАТЕЖНОЙ_СИСТЕМЫ;  // Вынести

    public static function registerEvents()
    {
        $em = EventManager::getInstance();
        $em->addEventHandler('sale', 'OnSaleComponentOrderResultPrepared', [self::class, 'modifyFrontendTotal']);
        $em->addEventHandler('sale', 'OnSaleOrderBeforeSaved', [self::class, 'recalculateBackendTotal']);
    }

    public static function modifyFrontendTotal(Order $order, &$arUserResult, $request, &$arParams, &$arResult)
    {
        if (($arUserResult['PAY_SYSTEM_ID'] ?? 0) != self::PAY_SYSTEM_ID) {
            return;
        }

        $t = &$arResult['JS_DATA']['TOTAL'];
        $discount = (float)($t['DISCOUNT_PRICE'] ?? 0);

        if ($discount <= 0) {
            return;
        }

        $total = $t['ORDER_TOTAL_PRICE'] + $discount;
        $currency = $order->getCurrency();
        
        $formatted = \CCurrencyLang::CurrencyFormat($total, $currency);

        $t['ORDER_PRICE'] = $t['ORDER_TOTAL_PRICE'] = $total;
        $t['ORDER_PRICE_FORMATED'] = $t['ORDER_TOTAL_PRICE_FORMATED'] = $formatted;
        $t['DISCOUNT_PRICE'] = 0;
        $t['DISCOUNT_PRICE_FORMATED'] = '';
        
        $arResult['ORDER_TOTAL_PRICE'] = $total;
        $arResult['ORDER_TOTAL_PRICE_FORMATED'] = $formatted;
    }

    public static function recalculateBackendTotal(\Bitrix\Main\Event $event)
    {
        $order = $event->getParameter('ENTITY');
        
        if (!$order) return;

        if (!$order->isNew()) return;

        $paymentCollection = $order->getPaymentCollection();
        $hasInstallment = false;

        foreach ($paymentCollection as $payment) {
            if ($payment->getPaymentSystemId() == self::PAY_SYSTEM_ID) {
                $hasInstallment = true;
                break;
            }
        }

        if (!$hasInstallment) return;

        $basket = $order->getBasket();
        $isBasketChanged = false;

        foreach ($basket as $item) {
            if ($item->getPrice() < $item->getBasePrice()) {
                $item->setFields([
                    'PRICE' => $item->getBasePrice(),
                    'CUSTOM_PRICE' => 'Y'
                ]);
                $isBasketChanged = true;
            }
        }

        if ($isBasketChanged) {
            
            $order->refreshData(); 
            
            $orderSum = $order->getPrice();
            $paidSum = 0;
            $installmentPayment = null;

            foreach ($paymentCollection as $payment) {
                if ($payment->getPaymentSystemId() == self::PAY_SYSTEM_ID) {
                    $installmentPayment = $payment;
                } else {
                    $paidSum += $payment->getSum();
                }
            }

            if ($installmentPayment) {
                $installmentPayment->setField('SUM', $orderSum - $paidSum);
            }
        }
    }
}
