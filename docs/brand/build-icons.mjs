#!/usr/bin/env node
//
// Renders every raster brand asset from resources/brand/cass-mark.svg.
//
// sharp and png-to-ico are deliberately NOT in the repository's package.json:
// they are a one-off authoring dependency (sharp ships ~40 MB of prebuilt
// libvips binaries per platform) and the rendered files are committed, so no
// deploy or CI job ever needs them. Run this from a scratch directory:
//
//     mkdir /tmp/cass-icons && cd /tmp/cass-icons
//     npm init -y && npm i sharp png-to-ico
//     cp <repo>/docs/brand/build-icons.mjs .
//     node build-icons.mjs <repo>
//
// The script is copied into the scratch directory rather than run in place
// because Node resolves a bare `import sharp` from the importing file's own
// directory upwards, not from the working directory.
//
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import pngToIco from 'png-to-ico';

const root = process.argv[2]
    ? resolve(process.argv[2])
    : resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const mark = readFileSync(join(root, 'resources/brand/cass-mark.svg'));
const publicDir = join(root, 'public');
const brandDir = join(publicDir, 'brand');

mkdirSync(brandDir, { recursive: true });

// librsvg rasterises the SVG at this DPI before sharp resizes it down, so the
// density - not the target size - is what keeps the gradients banding-free and
// the thin feather tips from breaking up. 600 is far past every output here.
const DENSITY = 600;

// Height, not width: the mark is portrait (viewBox 434.18 x 725.09) and every
// place it appears is height-constrained (a topbar, an email header row).
const renderToHeight = (height) =>
    sharp(mark, { density: DENSITY }).resize({ height, fit: 'inside' }).png({ compressionLevel: 9 }).toBuffer();

/**
 * The mark centred on a square canvas with `padding` of the side left clear on
 * each edge. `background` null keeps the canvas transparent.
 */
const renderToSquare = async (side, padding, background = null) => {
    const inner = Math.round(side * (1 - 2 * padding));
    const markPng = await renderToHeight(inner);

    return sharp({
        create: {
            width: side,
            height: side,
            channels: 4,
            background: background ?? { r: 0, g: 0, b: 0, alpha: 0 },
        },
    })
        .composite([{ input: markPng, gravity: 'centre' }])
        .png({ compressionLevel: 9 })
        .toBuffer();
};

const write = (path, buffer) => {
    writeFileSync(join(publicDir, path), buffer);

    return path;
};

const written = [];

// Email header. Clients do not render SVG reliably, and several ignore the
// width attribute, so this is the mark at 4x the 36px CSS height it is shown at.
written.push(write('brand/cass-mark-144.png', await renderToHeight(144)));

// Large transparent render kept for anything that cannot take an SVG - dompdf
// in particular cannot rasterise SVG gradients at all.
written.push(write('brand/cass-mark-600.png', await renderToHeight(600)));

// iOS ignores alpha in a touch icon and composites it onto black, so this one
// is flattened onto opaque white. It is full-bleed white rather than a drawn
// rounded rectangle because iOS applies its own superellipse mask over the top:
// drawing the corners here would only show white-on-white under that mask.
written.push(
    write(
        'apple-touch-icon.png',
        await renderToSquare(180, 0.12, { r: 255, g: 255, b: 255, alpha: 1 }),
    ),
);

// Web app manifest sizes, transparent.
written.push(write('icon-192.png', await renderToSquare(192, 0.1)));
written.push(write('icon-512.png', await renderToSquare(512, 0.1)));

// favicon.ico carries all three classic sizes; Windows and older browsers pick
// whichever they want out of the one file.
const icoSources = await Promise.all([16, 32, 48].map((side) => renderToSquare(side, 0.06)));
written.push(write('favicon.ico', await pngToIco(icoSources)));

// Verify what actually landed on disk. sharp cannot read ICO, so that one is
// checked by walking its own directory table: a 6-byte header (reserved, type
// 1, image count) followed by one 16-byte entry per image, whose first two
// bytes are width and height with 0 meaning 256.
const describeIco = (buffer) => {
    const count = buffer.readUInt16LE(4);
    const sizes = Array.from({ length: count }, (_, i) => {
        const entry = 6 + i * 16;

        return `${buffer[entry] || 256}x${buffer[entry + 1] || 256}`;
    });

    return `ico ${sizes.join(' ')}`;
};

for (const path of written) {
    const absolute = join(publicDir, path);
    const buffer = readFileSync(absolute);

    if (path.endsWith('.ico')) {
        console.log(`${path.padEnd(28)} ${describeIco(buffer)}`);

        continue;
    }

    const { width, height, format } = await sharp(buffer).metadata();
    console.log(`${path.padEnd(28)} ${format} ${width}x${height}`);
}
