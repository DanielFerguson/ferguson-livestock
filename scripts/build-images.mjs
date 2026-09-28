/**
 * Generates the responsive AVIF and WebP variants listed in resources/images/variants.json
 * into resources/images/generated/, where Vite picks them up for Vite::asset().
 *
 * Each variant is resized (and cropped to `aspect` when given) to every width in `widths`.
 * The build fails if a width is larger than the cropped source, because Blade would
 * otherwise advertise a size that doesn't exist.
 *
 * Run with: npm run images (also runs before every build)
 */

import sharp from 'sharp';
import { mkdir, readFile, stat } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const imagesDir = join(root, 'resources/images');
const outputDir = join(imagesDir, 'generated');
const configPath = join(imagesDir, 'variants.json');

const DEFAULT_QUALITY = { avif: 50, webp: 72 };

async function modifiedAt(path) {
    try {
        return (await stat(path)).mtimeMs;
    } catch {
        return 0;
    }
}

function parseAspect(aspect) {
    const [width, height] = aspect.split(':').map(Number);
    if (!width || !height) {
        throw new Error(`Invalid aspect "${aspect}"; expected "width:height", e.g. "4:5".`);
    }

    return { width, height };
}

async function buildVariant(name, variant, configModifiedAt) {
    const sourcePath = join(imagesDir, variant.source);
    const { width: sourceWidth, height: sourceHeight } = await sharp(sourcePath).metadata();
    const aspect = variant.aspect ? parseAspect(variant.aspect) : { width: sourceWidth, height: sourceHeight };

    // The widest image a crop to this aspect can produce without upscaling.
    const maxWidth = Math.floor(Math.min(sourceWidth, (sourceHeight * aspect.width) / aspect.height));
    const quality = { ...DEFAULT_QUALITY, ...variant.quality };
    const inputsModifiedAt = Math.max(configModifiedAt, await modifiedAt(sourcePath));

    let written = 0;

    for (const width of variant.widths) {
        if (width > maxWidth) {
            throw new Error(`"${name}" asks for ${width}px, but ${variant.source} can only produce ${maxWidth}px at this aspect.`);
        }

        const height = Math.round((width * aspect.height) / aspect.width);

        for (const format of ['avif', 'webp']) {
            const outputPath = join(outputDir, `${name}-${width}.${format}`);

            if ((await modifiedAt(outputPath)) > inputsModifiedAt) {
                continue;
            }

            await sharp(sourcePath)
                .resize({ width, height, fit: 'cover', position: variant.position ?? 'centre' })
                [format]({ quality: quality[format] })
                .toFile(outputPath);

            written++;
        }
    }

    return written;
}

const config = JSON.parse(await readFile(configPath, 'utf8'));
const configModifiedAt = await modifiedAt(configPath);

await mkdir(outputDir, { recursive: true });

let written = 0;
for (const [name, variant] of Object.entries(config)) {
    written += await buildVariant(name, variant, configModifiedAt);
}

console.log(`Responsive images ready (${written} written, ${Object.keys(config).length} variants).`);
