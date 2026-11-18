<?php

declare(strict_types=1);

namespace ShopBite\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateDefinition;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Compiled definitions of the order family and a few entities that point to orders.
 */
trait OrderDefinitions
{
    private static function registry(TestCase $test): DefinitionInstanceRegistry
    {
        $stub = new \ReflectionMethod($test, 'createStub');

        return new StaticDefinitionInstanceRegistry(
            [
                SalesChannelDefinition::class,
                OrderDefinition::class,
                OrderLineItemDefinition::class,
                OrderCustomerDefinition::class,
                CustomerDefinition::class,
                StateMachineStateDefinition::class,
                ProductDefinition::class,
            ],
            $stub->invoke($test, ValidatorInterface::class),
            $stub->invoke($test, EntityWriteGatewayInterface::class),
        );
    }
}
