<?php

use App\Services\JoinUrl;

it('normalizes moderator input to an absolute http(s) url', function (string $input, ?string $expected) {
    expect((new JoinUrl)->normalize($input))->toBe($expected);
})->with([
    'local host with port' => ['localhost:8010', 'http://localhost:8010'],
    'lan ip' => ['192.168.15.153:8090', 'http://192.168.15.153:8090'],
    'domain' => ['hitster.musikverein.at', 'https://hitster.musikverein.at'],
    'short link' => ['bit.ly/mv-hitster', 'https://bit.ly/mv-hitster'],
    'with scheme' => ['http://example.org/play/{code}', 'http://example.org/play/{code}'],
    'trimmed' => ['  https://tt.example.org/  ', 'https://tt.example.org/'],
    'empty' => ['', null],
    'javascript' => ['javascript:alert(1)', null],
    'ftp' => ['ftp://example.org', null],
    'spaces' => ['not a url', null],
]);
