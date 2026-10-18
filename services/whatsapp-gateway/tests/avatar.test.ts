import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    downloadProfilePicture,
    MAX_PROFILE_PICTURE_BYTES,
} from '../src/whatsapp/baileys-adapter.ts';

const jpeg = Buffer.concat([
    Buffer.from([0xff, 0xd8, 0xff, 0xe0]),
    Buffer.alloc(100),
]);
const respond = (body: Buffer, status = 200) =>
    (async () => new Response(body, { status })) as unknown as typeof fetch;

test('accepts a JPEG from the WhatsApp CDN', async () => {
    const picture = await downloadProfilePicture(
        'https://pps.whatsapp.net/v/t61/abc.jpg',
        respond(jpeg),
    );

    assert.equal(picture?.mimeType, 'image/jpeg');
    assert.equal(picture?.data.length, jpeg.length);
});

test('refuses URLs outside the WhatsApp CDN without fetching them', async () => {
    let fetched = false;
    const spy = (async () => {
        fetched = true;

        return new Response(jpeg);
    }) as unknown as typeof fetch;

    assert.equal(
        await downloadProfilePicture('https://evil.example.com/a.jpg', spy),
        null,
    );
    assert.equal(
        await downloadProfilePicture('http://pps.whatsapp.net/a.jpg', spy),
        null,
    );
    assert.equal(fetched, false);
});

test('refuses non-images, oversized images and failed downloads', async () => {
    const url = 'https://pps.whatsapp.net/v/t61/abc.jpg';

    assert.equal(
        await downloadProfilePicture(
            url,
            respond(Buffer.from('<svg onload=alert(1)>')),
        ),
        null,
    );
    assert.equal(
        await downloadProfilePicture(
            url,
            respond(
                Buffer.concat([jpeg, Buffer.alloc(MAX_PROFILE_PICTURE_BYTES)]),
            ),
        ),
        null,
    );
    assert.equal(await downloadProfilePicture(url, respond(jpeg, 403)), null);
});
