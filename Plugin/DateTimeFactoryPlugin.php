<?php
/**
 * @vendor Born
 * @package Born_Sales
 */

declare(strict_types=1);

namespace Born\VertexTax\Plugin;

use Vertex\Tax\Model\DateTimeImmutableFactory;

/**
 * Class DateTimeFactoryPlugin
 * @package Born\VertexTax\Plugin
 */
class DateTimeFactoryPlugin
{
    /**
     * EST Timezone
     */
    const TIMEZONE = 'EST';

    /**
     * Sets Timezone to EST on vertex time zone factory create function
     *
     * @param DateTimeImmutableFactory $subject
     * @param string                   $time
     * @param \DateTimeZone|null       $timezone
     *
     * @return array
     */
    public function beforeCreate(DateTimeImmutableFactory $subject, string $time = 'now', \DateTimeZone $timezone = null): array
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);

        return [$time, $timezone];
    }
}
