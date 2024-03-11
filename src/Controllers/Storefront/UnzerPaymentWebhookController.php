<?php

declare(strict_types=1);

namespace UnzerPayment6\Controllers\Storefront;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Throwable;
use Traversable;
use UnzerPayment6\Components\ConfigReader\ConfigReader;
use UnzerPayment6\Components\ConfigReader\ConfigReaderInterface;
use UnzerPayment6\Components\Struct\Configuration;
use UnzerPayment6\Components\Struct\Webhook;
use UnzerPayment6\Components\WebhookHandler\WebhookHandlerInterface;

/**
 * @RouteScope(scopes={"storefront"})
 * @Route(defaults={"_routeScope": {"storefront"}})
 */
class UnzerPaymentWebhookController extends StorefrontController
{
    /** @var Traversable|WebhookHandlerInterface[] */
    private $handlers;

    /** @var ConfigReaderInterface */
    private $configReader;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(Traversable $handlers, ConfigReaderInterface $configReader, LoggerInterface $logger)
    {
        $this->handlers     = $handlers;
        $this->configReader = $configReader;
        $this->logger       = $logger;
    }

    /**
     * @Route("/unzer/webhook", name="frontend.unzer.webhook.execute", methods={"POST", "GET"}, defaults={"csrf_protected": false})
     */
    public function execute(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        /** @var false|string $requestContent */
        $requestContent = $request->getContent();

        if (empty($requestContent)) {
            $this->logger->error('The webhook was not executed due to missing data.');

            return new Response('The webhook was not executed due to missing data.', Response::HTTP_BAD_REQUEST);
        }

        $webhook = new Webhook($requestContent);
        $config  = $this->configReader->read($salesChannelContext->getSalesChannel()->getId());

        if (!$this->isValidPublicKey($webhook, $config)) {
            $this->logger->error('The provided public key does not match the configured public key');

            return new Response('The provided public key does not match the configured public key.', Response::HTTP_FORBIDDEN);
        }

        foreach ($this->handlers as $handler) {
            if (!$handler->supports($webhook, $salesChannelContext)) {
                continue;
            }

            try {
                $this->logger->debug(
                    sprintf(
                        'Started handling of incoming webhook with content: %s',
                        json_encode($request->getContent())
                    )
                );

                $handler->execute($webhook, $salesChannelContext);
            } catch (Throwable $exception) {
                $this->logger->error(
                    'An exception was caught when handling a webhook, but this may not be a failure.',
                    [
                        'message' => $exception->getMessage(),
                        'code'    => $exception->getCode(),
                        'file'    => $exception->getFile(),
                        'line'    => $exception->getLine(),
                        'trace'   => $exception->getTraceAsString(),
                    ]
                );
            }
        }

        return new Response();
    }

    public function isValidPublicKey(Webhook $webhook, Configuration $config): bool
    {
        if ($webhook->getPublicKey() === $config->get(ConfigReader::CONFIG_KEY_PUBLIC_KEY)) {
            return true;
        }

        $paylaterInvoiceKeys = $config->get(ConfigReader::CONFIG_KEY_PAYLATER_INVOICE);

        if (is_array($paylaterInvoiceKeys)) {
            foreach ($paylaterInvoiceKeys as $keyPairConfig) {
                if ($keyPairConfig['publicKey'] === $webhook->getPublicKey()) {
                    return true;
                }
            }
        }

        $paylaterInstallmentKeys = $config->get(ConfigReader::CONFIG_KEY_PAYLATER_INSTALLMENT);

        if (is_array($paylaterInstallmentKeys)) {
            foreach ($paylaterInstallmentKeys as $keyPairConfig) {
                if ($keyPairConfig['publicKey'] === $webhook->getPublicKey()) {
                    return true;
                }
            }
        }

        $paylaterDirectDebitKeys = $config->get(ConfigReader::CONFIG_KEY_PAYLATER_DIRECT_DEBIT_SECURED);

        if (is_array($paylaterDirectDebitKeys)) {
            foreach ($paylaterDirectDebitKeys as $keyPairConfig) {
                if ($keyPairConfig['publicKey'] === $webhook->getPublicKey()) {
                    return true;
                }
            }
        }

        return false;
    }
}
