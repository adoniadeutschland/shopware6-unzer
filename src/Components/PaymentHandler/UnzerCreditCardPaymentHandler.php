<?php

declare(strict_types=1);

namespace UnzerPayment6\Components\PaymentHandler;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;
use UnzerPayment6\Components\BookingMode;
use UnzerPayment6\Components\ClientFactory\ClientFactoryInterface;
use UnzerPayment6\Components\ConfigReader\ConfigReader;
use UnzerPayment6\Components\ConfigReader\ConfigReaderInterface;
use UnzerPayment6\Components\CustomFieldsHelper\CustomFieldsHelperInterface;
use UnzerPayment6\Components\PaymentHandler\Exception\UnzerPaymentProcessException;
use UnzerPayment6\Components\PaymentHandler\Traits\CanAuthorize;
use UnzerPayment6\Components\PaymentHandler\Traits\CanCharge;
use UnzerPayment6\Components\PaymentHandler\Traits\HasDeviceVault;
use UnzerPayment6\Components\ResourceHydrator\CustomerResourceHydrator\CustomerResourceHydratorInterface;
use UnzerPayment6\Components\ResourceHydrator\ResourceHydratorInterface;
use UnzerPayment6\Components\TransactionStateHandler\TransactionStateHandlerInterface;
use UnzerPayment6\DataAbstractionLayer\Entity\PaymentDevice\UnzerPaymentDeviceEntity;
use UnzerPayment6\DataAbstractionLayer\Repository\PaymentDevice\UnzerPaymentDeviceRepositoryInterface;
use UnzerSDK\Constants\RecurrenceTypes;
use UnzerSDK\Exceptions\UnzerApiException;
use UnzerSDK\Resources\PaymentTypes\BasePaymentType;
use UnzerSDK\Resources\PaymentTypes\Card;

class UnzerCreditCardPaymentHandler extends AbstractUnzerPaymentHandler
{
    use CanCharge;
    use CanAuthorize;
    use HasDeviceVault;

    public const REMEMBER_CREDIT_CARD_KEY = 'creditCardRemember';

    /** @var BasePaymentType|Card */
    protected $paymentType;

    public function __construct(
        ResourceHydratorInterface             $basketHydrator,
        CustomerResourceHydratorInterface     $customerHydrator,
        ResourceHydratorInterface             $metadataHydrator,
        EntityRepository                      $transactionRepository,
        ConfigReaderInterface                 $configReader,
        TransactionStateHandlerInterface      $transactionStateHandler,
        ClientFactoryInterface                $clientFactory,
        RequestStack                          $requestStack,
        LoggerInterface                       $logger,
        CustomFieldsHelperInterface           $customFieldsHelper,
        UnzerPaymentDeviceRepositoryInterface $deviceRepository
    )
    {
        parent::__construct(
            $basketHydrator,
            $customerHydrator,
            $metadataHydrator,
            $transactionRepository,
            $configReader,
            $transactionStateHandler,
            $clientFactory,
            $requestStack,
            $logger,
            $customFieldsHelper
        );

        $this->deviceRepository = $deviceRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag                $dataBag,
        SalesChannelContext           $salesChannelContext
    ): RedirectResponse
    {
        parent::pay($transaction, $dataBag, $salesChannelContext);

        if ($this->paymentType === null) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransaction()->getId(), 'Can not process payment without a valid payment resource.');
        }

        $customer = $salesChannelContext->getCustomer();
        $bookingMode = $this->pluginConfig->get(ConfigReader::CONFIG_KEY_BOOKING_MODE_CARD, BookingMode::CHARGE);
        $registerCreditCards = $dataBag->has(self::REMEMBER_CREDIT_CARD_KEY);
        $saveToDeviceVault = $this->canSaveToDeviceVault($registerCreditCards, $customer);

        try {
            $recurrenceType = ($this->deviceRepository->exists($this->paymentType->getId(), $salesChannelContext->getContext()) || $saveToDeviceVault)
                ? RecurrenceTypes::ONE_CLICK
                : null;

            $returnUrl = $bookingMode === BookingMode::CHARGE
                ? $this->charge($transaction->getReturnUrl(), $recurrenceType)
                : $this->authorize($transaction->getReturnUrl(), $this->unzerBasket->getTotalValueGross(), $recurrenceType);

            if ($saveToDeviceVault) {
                $this->saveToDeviceVault(
                    $customer,
                    UnzerPaymentDeviceEntity::DEVICE_TYPE_CREDIT_CARD,
                    $salesChannelContext->getContext()
                );
            }

            return new RedirectResponse($returnUrl);
        } catch (UnzerApiException $apiException) {
            $this->logger->error(
                sprintf('Caught an API exception in %s of %s', __METHOD__, __CLASS__),
                [
                    'dataBag' => $dataBag,
                    'transaction' => $transaction,
                    'exception' => $apiException,
                ]
            );

            $this->executeFailTransition(
                $transaction->getOrderTransaction()->getId(),
                $salesChannelContext->getContext()
            );

            throw new UnzerPaymentProcessException($transaction->getOrder()->getId(), $transaction->getOrderTransaction()->getId(), $apiException);
        } catch (Throwable $exception) {
            $this->logger->error(
                sprintf('Caught a generic exception in %s of %s', __METHOD__, __CLASS__),
                [
                    'dataBag' => $dataBag,
                    'transaction' => $transaction,
                    'exception' => $exception,
                ]
            );

            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransaction()->getId(), $exception->getMessage());
        }
    }

    protected function canSaveToDeviceVault(bool $registerCreditCards, ?CustomerEntity $customer): bool
    {
        return $registerCreditCards && $customer !== null && $customer->getGuest() === false;
    }
}
