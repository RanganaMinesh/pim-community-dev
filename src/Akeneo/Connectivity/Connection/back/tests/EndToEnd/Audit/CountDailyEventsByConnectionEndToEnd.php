<?php

declare(strict_types=1);

namespace Akeneo\Connectivity\Connection\back\tests\EndToEnd\Audit;

use Akeneo\Connectivity\Connection\back\tests\EndToEnd\WebTestCase;
use Akeneo\Connectivity\Connection\back\tests\Integration\Fixtures\AuditLoader;
use Akeneo\Connectivity\Connection\Domain\Audit\Model\AllConnectionCode;
use Akeneo\Connectivity\Connection\Domain\Audit\Model\HourlyInterval;
use Akeneo\Connectivity\Connection\Domain\Audit\Model\Write\HourlyEventCount;
use Akeneo\Connectivity\Connection\Domain\Settings\Model\ValueObject\FlowType;
use Akeneo\Test\Integration\Configuration;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author Romain Monceau <romain@akeneo.com>
 * @copyright 2019 Akeneo SAS (http://www.akeneo.com)
 * @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */
class CountDailyEventsByConnectionEndToEnd extends WebTestCase
{
    public function test_it_finds_connections_event_by_created_product()
    {
        $this->createConnection('bynder', 'Bynder', FlowType::DATA_SOURCE);
        $this->createConnection('sap', 'SAP', FlowType::DATA_SOURCE);

        $this->createHourlyEventCounts([
            ['bynder', 'product_created', '2019-12-31 23:00:00', 1], // ignored
            ['bynder', 'product_created', '2020-01-01 00:00:00', 3],
            ['bynder', 'product_created', '2020-01-04 15:00:00', 5],
            ['bynder', 'product_created', '2020-01-04 16:00:00', 7],
            ['bynder', 'product_created', '2020-01-09 00:00:00', 9], // ignored
            ['sap', 'product_created', '2020-01-04 14:00:00', 10],
            ['sap', 'product_created', '2020-01-04 15:00:00', 30],
            ['sap', 'product_created', '2020-01-08 23:00:00', 50],
            [AllConnectionCode::CODE, 'product_created', '2020-01-01 00:00:00', 100],
        ], 'Asia/Tokyo');

        $expectedResult = [
            'bynder' => [
                '2020-01-01' => 3,
                '2020-01-02' => 0,
                '2020-01-03' => 0,
                '2020-01-04' => 12,
                '2020-01-05' => 0,
                '2020-01-06' => 0,
                '2020-01-07' => 0,
                '2020-01-08' => 0,
            ],
            'sap' => [
                '2020-01-01' => 0,
                '2020-01-02' => 0,
                '2020-01-03' => 0,
                '2020-01-04' => 40,
                '2020-01-05' => 0,
                '2020-01-06' => 0,
                '2020-01-07' => 0,
                '2020-01-08' => 50,
            ],
            '<all>' => [
                '2020-01-01' => 100,
                '2020-01-02' => 0,
                '2020-01-03' => 0,
                '2020-01-04' => 0,
                '2020-01-05' => 0,
                '2020-01-06' => 0,
                '2020-01-07' => 0,
                '2020-01-08' => 0,
            ]
        ];

        $user = $this->authenticateAsAdmin();
        $user->setTimezone('Asia/Tokyo');
        $this->get('pim_user.saver.user')->save($user);

        $this->client->request(
            'GET',
            '/rest/connections/audit/source-connections-event',
            [
                'event_type' => 'product_created',
                'end_date' => '2020-01-08'
            ],
        );
        $result = json_decode($this->client->getResponse()->getContent(), true);

        Assert::assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        Assert::assertEquals($expectedResult, $result);
    }

    protected function getConfiguration(): Configuration
    {
        return $this->catalog->useMinimalCatalog();
    }

    private function getAuditLoader(): AuditLoader
    {
        return $this->get('akeneo_connectivity.connection.fixtures.audit_loader');
    }

    private function createHourlyEventCounts(array $hourlyEventCountData, string $userDateTimeZoneStr): void
    {
        foreach ($hourlyEventCountData as [$connectionCode, $eventType, $userDateTimeStr, $eventCount]) {
            $utcDateTime = (new \DateTimeImmutable($userDateTimeStr, new \DateTimeZone($userDateTimeZoneStr)))
                ->setTimezone(new \DateTimeZone('UTC'));

            $hourlyEventCount = new HourlyEventCount(
                $connectionCode,
                HourlyInterval::createFromDateTime($utcDateTime),
                $eventCount,
                $eventType
            );

            $this->getAuditLoader()->insert($hourlyEventCount);
        }
    }
}