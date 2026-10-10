<?php

declare(strict_types=1);

namespace slash197\Reges\Laravel\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use slash197\Reges\Credentials;
use slash197\Reges\Data\InfoSalariat;
use slash197\Reges\Exception\ValidationException;
use slash197\Reges\Laravel\CacheNomenclatorLookup;
use slash197\Reges\Laravel\RegesFactory;
use slash197\Reges\Laravel\Tests\TestCase;
use slash197\Reges\Message;
use slash197\Reges\Operation;
use slash197\Reges\Reges;
use slash197\Reges\Validation\NomenclatorLookup;

final class CacheNomenclatorLookupTest extends TestCase
{
    public function testAcceptsAnyValueUntilThereIsALocalCopy(): void
    {
        $lookup = $this->lookup();

        self::assertFalse($lookup->holds(NomenclatorLookup::NATIONALITATE));
        self::assertTrue($lookup->has(NomenclatorLookup::NATIONALITATE, 'Atlantida'));
    }

    public function testRefreshDownloadsTheNomenclatorsWithoutAuthenticating(): void
    {
        $this->fakeNomenclators();

        $counts = $this->lookup()->refresh($this->app()->make(Reges::class));

        self::assertSame(['nationalitate' => 2, 'tipactidentitate' => 2, 'localitate' => 2], $counts);
        self::assertSame(array_fill(0, 3, 'GET /api/Nomenclator'), $this->sent());
        self::assertSame(
            ['tip=Nationalitate', 'tip=TipActIdentitate', 'tip=Localitate'],
            array_map(static fn ($request): string => $request->getUri()->getQuery(), $this->requests),
        );
    }

    public function testChecksValuesAgainstTheLocalCopy(): void
    {
        $this->fakeNomenclators();
        $lookup = $this->lookup();
        $lookup->refresh($this->app()->make(Reges::class));

        self::assertTrue($lookup->has(NomenclatorLookup::NATIONALITATE, 'România'));
        self::assertFalse($lookup->has(NomenclatorLookup::NATIONALITATE, 'Romania'));
        self::assertTrue($lookup->has(NomenclatorLookup::TIP_ACT_IDENTITATE, 'CarteIdentitate'));
        self::assertFalse($lookup->has(NomenclatorLookup::TIP_ACT_IDENTITATE, 'Carte de identitate'));
        self::assertTrue($lookup->has(NomenclatorLookup::LOCALITATE, '54984'));
        self::assertFalse($lookup->has(NomenclatorLookup::LOCALITATE, '1'));
    }

    public function testTheCopyOutlivesTheInstanceThatDownloadedIt(): void
    {
        $this->fakeNomenclators();
        $this->lookup()->refresh($this->app()->make(Reges::class));
        $this->app()->forgetInstance(CacheNomenclatorLookup::class);

        self::assertTrue($this->lookup()->holds(NomenclatorLookup::LOCALITATE));
        self::assertFalse($this->lookup()->has(NomenclatorLookup::LOCALITATE, '1'));
    }

    public function testKeepsTheCopyWhenRegesAnswersWithAnEmptyList(): void
    {
        $this->fakeNomenclators();
        $lookup = $this->lookup();
        $lookup->refresh($this->app()->make(Reges::class));

        $this->fakeHttp($this->jsonResponse([]), $this->jsonResponse([]), $this->jsonResponse([]));
        $this->app()->forgetInstance(RegesFactory::class);
        $counts = $lookup->refresh($this->app()->make(RegesFactory::class)->make(new Credentials('', '')));

        self::assertSame(0, $counts[NomenclatorLookup::NATIONALITATE]);
        self::assertFalse($lookup->has(NomenclatorLookup::NATIONALITATE, 'Atlantida'));
    }

    public function testClientsValidateAgainstTheCopyOnceItExists(): void
    {
        $this->fakeNomenclators();
        $this->command('reges:nomenclators')->assertSuccessful();

        $reges = $this->app()->make(RegesFactory::class)
            ->make(new Credentials('7d2c6c32-643d-4746-8402-003b0f420eb4', 'password'));

        try {
            $reges->envelope(Message::salariat(Operation::InregistrareSalariat, new InfoSalariat(
                cnp: '1800612015459',
                nume: 'POPESCU',
                prenume: 'ION',
                adresa: 'STR. SALARIATULUI, NR. 1',
                tipActIdentitate: 'CarteIdentitate',
                taraDomiciliu: 'Atlantida',
                nationalitate: 'România',
            )), 'Maria Ionescu');
            self::fail('The unknown country should have been rejected.');
        } catch (ValidationException $exception) {
            self::assertSame(['info.taraDomiciliu.nume'], $exception->fields());
        }
    }

    public function testTheCommandFailsWhenRegesCannotBeReached(): void
    {
        $this->fakeHttp(new Response(503));

        $this->command('reges:nomenclators')->assertFailed();

        self::assertFalse($this->lookup()->holds(NomenclatorLookup::NATIONALITATE));
    }

    private function lookup(): CacheNomenclatorLookup
    {
        return $this->app()->make(CacheNomenclatorLookup::class);
    }

    private function fakeNomenclators(): void
    {
        $this->fakeHttp(
            $this->jsonResponse([['nume' => 'România', 'abreviere' => 'RO'], ['nume' => 'Muntenegru', 'abreviere' => 'ME']]),
            $this->jsonResponse([['nume' => 'Carte de identitate', 'cod' => 'CarteIdentitate'], ['nume' => 'Pasaport', 'cod' => 'Pasaport']]),
            $this->jsonResponse([['codSiruta' => 54984, 'nume' => 'CLUJ-NAPOCA'], ['codSiruta' => 7561, 'nume' => 'JOLDISESTI']]),
        );
    }
}
