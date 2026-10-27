<?php

namespace Platform\UserConnectors\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Alter Tool-Name, der auf ein konsolidiertes Tool weiterleitet. Bleibt
 * aufrufbar (Inbox-Routen, Agenten mit altem Wissen), ist aber als
 * deprecated markiert und taucht in der Tool-Suche nicht mehr auf.
 */
final class DeprecatedAliasTool implements ToolContract, ToolMetadataContract
{
    /**
     * @param array<string, mixed> $defaults Argumente, die gesetzt werden, sofern der Aufrufer sie nicht übergibt.
     * @param array<string, string> $renames Alte Argument-Namen → neue.
     */
    public function __construct(
        private readonly string $name,
        private readonly ToolContract $target,
        private readonly array $defaults = [],
        private readonly array $renames = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'Veraltet — bitte ' . $this->target->getName() . ' verwenden. ' . $this->target->getDescription();
    }

    public function getSchema(): array
    {
        $schema = $this->target->getSchema();
        foreach ($this->renames as $old => $new) {
            if (isset($schema['properties'][$new])) {
                $schema['properties'][$old] = $schema['properties'][$new];
            }
        }

        return $schema;
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        foreach ($this->renames as $old => $new) {
            if (array_key_exists($old, $arguments) && !array_key_exists($new, $arguments)) {
                $arguments[$new] = $arguments[$old];
                unset($arguments[$old]);
            }
        }

        // connector_key-Default nur ohne explizite Connection — sonst würde
        // er z. B. eine Vodafone-Connection-ID am RingCentral-Alias abweisen.
        $defaults = $this->defaults;
        if (!empty($arguments['connection_id'])) {
            unset($defaults['connector_key']);
        }

        return $this->target->execute($arguments + $defaults, $context);
    }

    public function getMetadata(): array
    {
        $metadata = $this->target instanceof ToolMetadataContract ? $this->target->getMetadata() : [];

        return array_merge($metadata, [
            'deprecated' => true,
            'successor_name' => $this->target->getName(),
            'tier' => 'hidden',
        ]);
    }
}
