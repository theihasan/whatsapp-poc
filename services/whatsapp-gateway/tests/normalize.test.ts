import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { WAMessage } from '@whiskeysockets/baileys';
import { classifyClose } from '../src/whatsapp/baileys-adapter.ts';
import {
    deliveryStatusFromProto,
    normalizeIncoming,
    phoneNumberFromJid,
    withResolvedPhone,
} from '../src/whatsapp/normalize.ts';

function message(overrides: Partial<WAMessage>): WAMessage {
    return {
        key: {
            id: 'ABC',
            remoteJid: '15550100101@s.whatsapp.net',
            fromMe: false,
        },
        messageTimestamp: 1_800_000_000,
        pushName: 'Alex',
        message: { conversation: 'hello' },
        ...overrides,
    } as WAMessage;
}

test('normalizes a plain text message from a phone-number JID', () => {
    const normalized = normalizeIncoming(message({}));

    assert.deepEqual(normalized, {
        externalId: 'ABC',
        from: {
            jid: '15550100101@s.whatsapp.net',
            lid: null,
            phoneNumber: '+15550100101',
            pushName: 'Alex',
        },
        chatType: 'direct',
        participant: null,
        rawType: 'conversation',
        timestamp: '2027-01-15T08:00:00.000Z',
        type: 'text',
        body: 'hello',
        mediaInfo: null,
    });
});

test('uses the alternate phone-number JID for LID-addressed chats', () => {
    const normalized = normalizeIncoming(
        message({
            key: {
                id: 'X',
                remoteJid: '123456789012345@lid',
                remoteJidAlt: '15550100102@s.whatsapp.net',
                fromMe: false,
            },
        }),
    );

    assert.equal(normalized?.from.jid, '15550100102@s.whatsapp.net');
    assert.equal(normalized?.from.phoneNumber, '+15550100102');
});

test('does not invent a phone number when only a LID is known', () => {
    const normalized = normalizeIncoming(
        message({
            key: { id: 'X', remoteJid: '123456789012345@lid', fromMe: false },
        }),
    );

    assert.equal(normalized?.from.jid, '123456789012345@lid');
    assert.equal(normalized?.from.phoneNumber, null);
});

test('skips own, broadcast and status messages', () => {
    for (const key of [
        { id: 'A', remoteJid: '15550100101@s.whatsapp.net', fromMe: true },
        { id: 'C', remoteJid: 'status@broadcast', fromMe: false },
    ]) {
        assert.equal(normalizeIncoming(message({ key })), null);
    }
});

test('extracts captions and media metadata from documents', () => {
    const normalized = normalizeIncoming(
        message({
            message: {
                documentMessage: {
                    caption: 'Invoice',
                    mimetype: 'application/pdf',
                    fileName: 'inv.pdf',
                    fileLength: 2048,
                },
            },
        }),
    );

    assert.equal(normalized?.type, 'document');
    assert.equal(normalized?.body, 'Invoice');
    assert.deepEqual(normalized?.mediaInfo, {
        mimeType: 'application/pdf',
        fileName: 'inv.pdf',
        size: 2048,
    });
});

test('marks unhandled content types as unsupported instead of dropping them', () => {
    const normalized = normalizeIncoming(
        message({
            message: {
                locationMessage: { degreesLatitude: 1, degreesLongitude: 2 },
            },
        }),
    );

    assert.equal(normalized?.type, 'unsupported');
    assert.equal(normalized?.rawType, 'locationMessage');
});

test('maps protocol delivery statuses and ignores pending', () => {
    assert.deepEqual([0, 1, 2, 3, 4, 5].map(deliveryStatusFromProto), [
        'failed',
        null,
        'sent',
        'delivered',
        'read',
        'read',
    ]);
});

test('extracts phone numbers only from phone-number JIDs', () => {
    assert.equal(
        phoneNumberFromJid('15550100101:3@s.whatsapp.net'),
        '+15550100101',
    );
    assert.equal(phoneNumberFromJid('123@lid'), null);
    assert.equal(phoneNumberFromJid(null), null);
});

test('classifies disconnect reasons', () => {
    const boom = (statusCode: number) =>
        Object.assign(new Error('closed'), { output: { statusCode } });

    assert.equal(classifyClose(boom(401), true).kind, 'logged_out');
    assert.equal(classifyClose(boom(515), false).kind, 'restart_required');
    assert.equal(classifyClose(boom(440), true).kind, 'connection_replaced');
    assert.equal(classifyClose(boom(408), false).kind, 'qr_timeout');
    assert.equal(classifyClose(boom(408), true).kind, 'transient');
    assert.equal(classifyClose(boom(403), true).kind, 'fatal');
    assert.equal(
        classifyClose(new Error('socket hang up'), true).kind,
        'transient',
    );
});

test('keeps the LID alongside the phone-number JID when both are known', () => {
    const normalized = normalizeIncoming(
        message({
            key: {
                id: 'X',
                remoteJid: '123456789012345@lid',
                remoteJidAlt: '15550100102@s.whatsapp.net',
                fromMe: false,
            },
        }),
    );

    assert.equal(normalized?.from.lid, '123456789012345@lid');
});

test('completes a LID-only sender from the local mapping and switches identity to the phone JID', () => {
    const lidOnly = normalizeIncoming(
        message({
            key: { id: 'X', remoteJid: '123456789012345@lid', fromMe: false },
        }),
    )!;

    const resolved = withResolvedPhone(
        lidOnly.from,
        '15550100103:0@s.whatsapp.net',
    );

    assert.deepEqual(resolved, {
        jid: '15550100103@s.whatsapp.net',
        lid: '123456789012345@lid',
        phoneNumber: '+15550100103',
        pushName: 'Alex',
    });
    assert.equal(withResolvedPhone(lidOnly.from, null), lidOnly.from);
});

test('keeps group messages with the group as chat and the participant as sender', () => {
    const normalized = normalizeIncoming(
        message({
            key: {
                id: 'G1',
                remoteJid: '120363000000000000@g.us',
                participant: '123456789012345@lid',
                participantAlt: '15550100105@s.whatsapp.net',
                fromMe: false,
            },
            pushName: 'Rina',
        }),
    );

    assert.equal(normalized?.chatType, 'group');
    assert.deepEqual(normalized?.from, {
        jid: '120363000000000000@g.us',
        lid: null,
        phoneNumber: null,
        pushName: null,
    });
    assert.deepEqual(normalized?.participant, {
        jid: '15550100105@s.whatsapp.net',
        lid: '123456789012345@lid',
        phoneNumber: '+15550100105',
        pushName: 'Rina',
    });
});

test('drops group messages without a known participant', () => {
    assert.equal(
        normalizeIncoming(
            message({
                key: {
                    id: 'G2',
                    remoteJid: '120363000000000000@g.us',
                    fromMe: false,
                },
            }),
        ),
        null,
    );
});
