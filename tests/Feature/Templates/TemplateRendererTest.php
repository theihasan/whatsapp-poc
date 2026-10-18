<?php

use App\Services\Templates\TemplateRenderer;

it('substitutes supported placeholders, tolerating inner spaces', function () {
    $rendered = app(TemplateRenderer::class)->render('Hi {{ contact_name }}, from {{company_name}}.', [
        'contact_name' => 'Ana',
        'company_name' => 'Acme',
    ]);

    expect($rendered)->toBe('Hi Ana, from Acme.');
});

it('never expands placeholder syntax that comes from a value', function () {
    $rendered = app(TemplateRenderer::class)->render('Hi {{contact_name}}!', ['contact_name' => '{{company_name}}']);

    expect($rendered)->toBe('Hi {{company_name}}!');
});

it('strips control characters from values', function () {
    $rendered = app(TemplateRenderer::class)->render('Hi {{contact_name}}', ['contact_name' => "Ana\x00\x1B[31m"]);

    expect($rendered)->toBe('Hi Ana[31m');
});

it('reports unsupported placeholders', function () {
    expect(app(TemplateRenderer::class)->unknownPlaceholders('{{contact_name}} {{password}} {{password}}'))->toBe(['password']);
});
