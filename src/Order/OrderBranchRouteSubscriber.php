<?php

declare(strict_types=1);

namespace ShopBite\Order;

use ShopBite\Staff\BranchBinding;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Admin API action routes on one order (state transitions, recalculation, documents, ...)
 * load the order themselves, partly in system scope. For users bound to branches, the order,
 * delivery, transaction or document named in the route must belong to one of their branches.
 *
 * @psalm-api
 */
final readonly class OrderBranchRouteSubscriber implements EventSubscriberInterface
{
    /** Route parameter => entity. */
    private const array PARAMETERS = [
        'orderId' => 'order',
        'orderDeliveryId' => 'order_delivery',
        'orderTransactionId' => 'order_transaction',
        'documentId' => 'document',
    ];

    /** @psalm-suppress PossiblyUnusedMethod Constructed by the container */
    public function __construct(
        private BranchBinding $branchBinding,
        private OrderSalesChannelResolver $resolver,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $params = $request->attributes->get('_route_params');
        if (!\is_array($params)) {
            return;
        }
        $targets = [];
        foreach (self::PARAMETERS as $parameter => $entity) {
            if (isset($params[$parameter])) {
                $targets[] = [$entity, $params[$parameter]];
            }
        }
        $entityName = $params['entityName'] ?? null;
        if (\is_string($entityName) && OrderFamily::contains($entityName) && isset($params['entityId'])) {
            $targets[] = [$entityName, $params['entityId']];
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT);
        if ($targets === [] || !$context instanceof Context) {
            return;
        }
        $salesChannelIds = $this->branchBinding->salesChannelIds($context);
        if ($salesChannelIds === null) {
            return;
        }

        foreach ($targets as [$entity, $id]) {
            $salesChannelId = \is_string($id) && Uuid::isValid($id) ? $this->resolver->ofRow($entity, Uuid::fromHexToBytes($id)) : null;
            if ($salesChannelId === null || !BranchBinding::permits($salesChannelIds, $salesChannelId)) {
                throw BranchAccessException::otherBranch($entity);
            }
        }
    }
}
