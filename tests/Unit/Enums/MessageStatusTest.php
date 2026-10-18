<?php

use App\Enums\MessageStatus;

it('only moves forward along the delivery ladder', function () {
    expect(MessageStatus::GatewayAccepted->canAdvanceTo(MessageStatus::Sent))->toBeTrue()
        ->and(MessageStatus::Delivered->canAdvanceTo(MessageStatus::Read))->toBeTrue()
        ->and(MessageStatus::Read->canAdvanceTo(MessageStatus::Delivered))->toBeFalse()
        ->and(MessageStatus::Delivered->canAdvanceTo(MessageStatus::Sent))->toBeFalse();
});

it('lets delivery evidence override failed and unknown outcomes', function (MessageStatus $from) {
    expect($from->canAdvanceTo(MessageStatus::Delivered))->toBeTrue()
        ->and($from->canAdvanceTo(MessageStatus::GatewayAccepted))->toBeFalse();
})->with([MessageStatus::Failed, MessageStatus::Unknown]);

it('never changes the status of received messages', function () {
    expect(MessageStatus::Received->canAdvanceTo(MessageStatus::Read))->toBeFalse();
});
