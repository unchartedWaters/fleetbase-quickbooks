<?php

use Fleetbase\Quickbooks\Support\Translation;
use Symfony\Component\Yaml\Yaml;

function qbTranslationKeys(): array
{
    $keys    = [];
    $flatten = function (array $tree, string $prefix) use (&$flatten, &$keys): void {
        foreach ($tree as $name => $value) {
            if (is_array($value) === true) {
                $flatten($value, $prefix . $name . '.');
            } else {
                $keys[$prefix . $name] = (string) $value;
            }
        }
    };
    $flatten(Yaml::parseFile(dirname(__DIR__, 2) . '/translations/en-us.yaml'), '');

    return $keys;
}

test('server text is read from the frontend translations file', function () {
    expect(Translation::get('quickbooks.connection.title', [], 'en-us'))->toBe('QuickBooks Setup')
        ->and(Translation::get('quickbooks.messages.sync-failed', [], 'en-us'))->toBe('Sync failed.');
});

test('placeholders are filled the way ember-intl fills them', function () {
    expect(Translation::get('quickbooks.widget.waiting', ['count' => 3], 'en-us'))->toBe('3 waiting')
        ->and(Translation::get('quickbooks.messages.request-failed-detail', [
            'status' => 1500,
            'detail' => "Name can't be {blank} </script>",
        ], 'en-us'))->toBe("QuickBooks request failed with status 1500: Name can't be {blank} </script>");
});

test('an unknown key comes back unchanged', function () {
    $missing = 'quickbooks.messages.does-not-exist';

    expect(Translation::get($missing, [], 'en-us'))->toBe($missing);
});

test('a locale without a translations file falls back to en-us', function () {
    expect(Translation::get('quickbooks.connection.title', [], 'fr-fr'))->toBe('QuickBooks Setup')
        ->and(Translation::normalize('EN_US'))->toBe('en-us')
        ->and(Translation::normalize('../../etc/passwd'))->toBe('en-us')
        ->and(Translation::normalize('fr-fr'))->toBe('en-us');
});

test('without a signed-in user the locale is en-us', function () {
    expect(Translation::currentLocale())->toBe('en-us');
});

test('stored keys render in the reader locale and other text is left alone', function () {
    $stored = Translation::encode(
        'quickbooks.messages.wallet-currency-mismatch',
        ['currency' => 'EUR', 'home' => 'USD']
    );
    $intuit = Translation::encode(
        'quickbooks.messages.request-failed-detail',
        ['status' => '400', 'detail' => 'Name "Café" {0} is taken']
    );
    $unknown = '{"key":"quickbooks.not.a.key","params":{}}';
    $legacy  = 'Sync failed because of an older English row.';

    expect(Translation::encode('quickbooks.messages.sync-failed'))->toBe('quickbooks.messages.sync-failed')
        ->and(Translation::render('quickbooks.messages.sync-failed', 'en-us'))->toBe('Sync failed.')
        ->and(Translation::render($stored, 'en-us'))
        ->toBe('Wallet currency EUR does not match the QuickBooks home currency USD.')
        ->and(Translation::render($intuit, 'en-us'))
        ->toBe('QuickBooks request failed with status 400: Name "Café" {0} is taken')
        ->and(Translation::render($legacy, 'en-us'))->toBe($legacy)
        ->and(Translation::render('quickbooks.not.a.key', 'en-us'))->toBe('quickbooks.not.a.key')
        ->and(Translation::render($unknown, 'en-us'))->toBe($unknown)
        ->and(Translation::render('{not json', 'en-us'))->toBe('{not json')
        ->and(Translation::render(null, 'en-us'))->toBeNull();
});

test('only known keys count as server messages', function () {
    $failed = Translation::encode('quickbooks.messages.request-failed', ['status' => '500']);

    expect(Translation::isMessage('quickbooks.messages.sync-failed'))->toBeTrue()
        ->and(Translation::isMessage($failed))->toBeTrue()
        ->and(Translation::isMessage('quickbooks.messages.nope'))->toBeFalse()
        ->and(Translation::isMessage('QuickBooks says no'))->toBeFalse();
});

test('a param with invalid UTF-8 keeps the rest of the message', function () {
    $stored = Translation::encode('quickbooks.messages.invoice-number-taken', ['number' => "INV\xB1"]);

    expect(Translation::render($stored, 'en-us'))->toStartWith('QuickBooks invoice number INV')
        ->and(Translation::render($stored, 'en-us'))->not->toContain('{number}');
});

test('a UI label key is not treated as a stored server message', function () {
    expect(Translation::isMessage('quickbooks.settings.entities.customer'))->toBeFalse()
        ->and(Translation::render('quickbooks.settings.entities.customer', 'en-us'))
        ->toBe('quickbooks.settings.entities.customer');
});

test('appended text renders as one message', function () {
    $note   = Translation::encode('quickbooks.messages.invoice-number-taken', ['number' => '1001']);
    $joined = Translation::append(Translation::append('Raw Intuit detail.', $note), 'quickbooks.messages.sync-failed');

    expect(Translation::render($joined, 'en-us'))->toBe('Raw Intuit detail. QuickBooks invoice number 1001 was not '
        . 'copied because another Fleetbase invoice already uses it. Sync failed.');
});

test('every translation parses as an ICU message', function () {
    foreach (qbTranslationKeys() as $key => $pattern) {
        expect(MessageFormatter::create('en-us', $pattern))->not->toBeNull($key);
    }
});

// Config and cache keys also start with quickbooks., so only the message groups are checked.
test('every message key named in the server code exists in en-us.yaml', function () {
    $keys    = qbTranslationKeys();
    $files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));
    $pattern = "/'(quickbooks\\.(?:messages|validation)\\.[a-z0-9.-]+)'/";
    $found   = 0;
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches);
        foreach ($matches[1] as $key) {
            $found++;
            expect(array_key_exists($key, $keys))->toBeTrue($key . ' in ' . $file->getFilename());
        }
    }

    expect($found)->toBeGreaterThan(40);
});
