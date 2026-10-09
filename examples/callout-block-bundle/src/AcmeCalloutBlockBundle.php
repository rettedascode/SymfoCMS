<?php

namespace Acme\CalloutBlock;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Example extension. Its templates (templates/) and translations (translations/)
 * are found automatically because they sit next to this bundle's src/ directory.
 */
final class AcmeCalloutBlockBundle extends AbstractBundle
{
    /**
     * @param array<array-key, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Autoconfiguration is all it takes: every service implementing
        // App\Block\BlockTypeInterface is offered in the editor.
        $container->services()
            ->set(CalloutBlock::class)
            ->autowire()
            ->autoconfigure();
    }
}
