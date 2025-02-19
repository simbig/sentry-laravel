<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Mockery;
use RuntimeException;
use Sentry\Event;
use Sentry\ExceptionMechanism;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;
use Sentry\Tracing\TransactionSource;
use function Sentry\withScope;

class IntegrationTest extends TestCase
{
    public function testIntegrationIsRegistered(): void
    {
        $integration = $this->getHubFromContainer()->getIntegration(Integration::class);

        $this->assertInstanceOf(Integration::class, $integration);
    }

    public function testTransactionIsSetWhenRouteMatchedEventIsFired(): void
    {
        Integration::setTransaction(null);

        $event = new RouteMatched(
            new Route('GET', $routeUrl = '/sentry-route-matched-event', []),
            Mockery::mock(Request::class)->makePartial()
        );

        $this->dispatchLaravelEvent($event);

        $this->assertSame($routeUrl, Integration::getTransaction());
    }

    public function testTransactionIsAppliedToEventWithoutTransaction(): void
    {
        Integration::setTransaction($transaction = 'some-transaction-name');

        withScope(function (Scope $scope) use ($transaction): void {
            $event = Event::createEvent();

            $this->assertNull($event->getTransaction());

            $event = $scope->applyToEvent($event);

            $this->assertNotNull($event);

            $this->assertSame($transaction, $event->getTransaction());
        });
    }

    public function testTransactionIsAppliedToEventWithEmptyTransaction(): void
    {
        Integration::setTransaction($transaction = 'some-transaction-name');

        withScope(function (Scope $scope) use ($transaction): void {
            $event = Event::createEvent();
            $event->setTransaction($emptyTransaction = '');

            $this->assertSame($emptyTransaction, $event->getTransaction());

            $event = $scope->applyToEvent($event);

            $this->assertNotNull($event);

            $this->assertSame($transaction, $event->getTransaction());
        });
    }

    public function testTransactionIsNotAppliedToEventWhenTransactionIsAlreadySet(): void
    {
        Integration::setTransaction('some-transaction-name');

        withScope(function (Scope $scope): void {
            $event = Event::createEvent();

            $event->setTransaction($eventTransaction = 'some-other-transaction-name');

            $this->assertSame($eventTransaction, $event->getTransaction());

            $event = $scope->applyToEvent($event);

            $this->assertNotNull($event);

            $this->assertSame($eventTransaction, $event->getTransaction());
        });
    }

    public function testExtractingNameForRouteWithoutName(): void
    {
        $route = new Route('GET', $url = '/foo', []);

        $this->assertRouteNameAndSource($route, $url, TransactionSource::route());
    }

    public function testExtractingNameForRouteWithAutoGeneratedName(): void
    {
        // We fake a generated name here, Laravel generates them each starting with `generated::`
        $route = (new Route('GET', $url = '/foo', []))->name('generated::KoAePbpBofo01ey4');

        $this->assertRouteNameAndSource($route, $url, TransactionSource::route());
    }

    public function testExtractingNameForRouteWithIncompleteGroupName(): void
    {
        $route = (new Route('GET', $url = '/foo', []))->name('group-name.');

        $this->assertRouteNameAndSource($route, $url, TransactionSource::route());
    }

    public function testExtractingNameForLumenRouteWithoutName(): void
    {
        $url = '/some-route';

        $this->assertLumenRouteNameAndSource([0, [], []], $url, $url, TransactionSource::route());
    }

    public function testExtractingNameForLumenRouteWithParamInUrl(): void
    {
        $route = [1, [], ['param1' => 'foo']];

        $url = '/foo/bar/baz';

        $this->assertLumenRouteNameAndSource($route, $url, '/{param1}/bar/baz', TransactionSource::route());
    }

    public function testExtractingNameForLumenRouteWithParamsInUrl(): void
    {
        $route = [1, [], ['param1' => 'foo', 'param2' => 'bar']];

        $url = '/foo/bar/baz';

        $this->assertLumenRouteNameAndSource($route, $url, '/{param1}/{param2}/baz', TransactionSource::route());
    }

    public function testExtractingNameForLumenRouteWithParamsWithSameValueInUrl(): void
    {
        $route = [1, [], ['param1' => 'foo', 'param2' => 'foo']];

        $url = '/foo/foo/bar';

        $this->assertLumenRouteNameAndSource($route, $url, '/{param1}/{param2}/bar', TransactionSource::route());
    }

    public function testExceptionReportedUsingReportHelperIsNotMarkedAsUnhandled(): void
    {
        $testException = new RuntimeException('This was handled');

        report($testException);

        $this->assertEquals(1, $this->getEventsCount());

        $hint = $this->getLastEventHint();

        $this->assertEquals($testException, $hint->exception);
        $this->assertNotNull($hint->mechanism);
        $this->assertTrue($hint->mechanism->isHandled());
    }

    public function testExceptionIsNotMarkedAsUnhandled(): void
    {
        $testException = new RuntimeException('This was not handled');

        Integration::captureUnhandledException($testException);

        $this->assertEquals(1, $this->getEventsCount());

        $hint = $this->getLastEventHint();

        $this->assertEquals($testException, $hint->exception);
        $this->assertNotNull($hint->mechanism);
        $this->assertFalse($hint->mechanism->isHandled());
    }

    private function assertRouteNameAndSource(Route $route, string $expectedName, TransactionSource $expectedSource): void
    {
        [$actualName, $actualSource] = Integration::extractNameAndSourceForRoute($route);

        $this->assertSame($expectedName, $actualName);
        $this->assertSame($expectedSource, $actualSource);
    }

    private function assertLumenRouteNameAndSource(array $routeData, string $path, string $expectedName, TransactionSource $expectedSource): void
    {
        [$actualName, $actualSource] = Integration::extractNameAndSourceForLumenRoute($routeData, $path);

        $this->assertSame($expectedName, $actualName);
        $this->assertSame($expectedSource, $actualSource);
    }
}
