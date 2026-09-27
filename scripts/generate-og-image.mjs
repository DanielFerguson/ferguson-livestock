/**
 * OG Image Generator for Ferguson Livestock
 * Uses Satori to convert JSX to SVG, then converts to JPEG
 * 
 * Run with: npm run og-image [-- <output path>]
 * Writes public/og-image.jpg unless an output path is given (handy for previewing).
 */

import satori from 'satori';
import { Resvg } from '@resvg/resvg-js';
import sharp from 'sharp';
import { readFile, writeFile } from 'fs/promises';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = join(__dirname, '..');

// OG Image dimensions (standard)
const WIDTH = 1200;
const HEIGHT = 630;

// Brand colours from the @theme tokens in resources/css/app.css
const colors = {
    forest: '#1a2e1a',
    sage: '#4a6741',
    mint: '#7db89e',
    cream: '#f5f2eb',
    warm: '#d4a574',
};

// Pinned so regenerating the image is reproducible.
const FONTSOURCE_VERSION = '5.3.0';

// Icons are drawn as SVG: the latin font subsets don't include ★ or ✓.
const STAR_PATH = 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z';
const CHECK_PATH = 'M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z';

function icon(path, size) {
    return {
        type: 'svg',
        props: {
            width: size,
            height: size,
            viewBox: '0 0 24 24',
            children: { type: 'path', props: { d: path, fill: colors.mint } },
        },
    };
}

function feature(label) {
    return {
        type: 'div',
        props: {
            style: { display: 'flex', alignItems: 'center', gap: '10px' },
            children: [
                icon(CHECK_PATH, 28),
                {
                    type: 'span',
                    props: {
                        style: { color: colors.cream, fontFamily: 'Source Sans 3', fontSize: '24px', fontWeight: 400 },
                        children: label,
                    },
                },
            ],
        },
    };
}

async function generateOGImage() {
    console.log('🖼️  Generating OG image...');

    // Load the background image and convert to base64
    const backgroundImagePath = join(rootDir, 'resources/images/cows-1.webp');
    const backgroundBuffer = await readFile(backgroundImagePath);

    // Convert webp to png for better compatibility with Satori
    const pngBuffer = await sharp(backgroundBuffer)
        .resize(WIDTH, HEIGHT, { fit: 'cover', position: 'center' })
        .png()
        .toBuffer();

    const backgroundBase64 = `data:image/png;base64,${pngBuffer.toString('base64')}`;

    // Load fonts - using system fonts available on most systems
    // We'll use Google Fonts that are commonly available
    const fontRegularPath = join(rootDir, 'scripts/fonts/SourceSans3-Regular.ttf');
    const fontBoldPath = join(rootDir, 'scripts/fonts/SourceSans3-Bold.ttf');
    const fontDisplayPath = join(rootDir, 'scripts/fonts/CormorantGaramond-SemiBold.ttf');

    let fonts = [];

    try {
        const [fontRegular, fontBold, fontDisplay] = await Promise.all([
            readFile(fontRegularPath),
            readFile(fontBoldPath),
            readFile(fontDisplayPath),
        ]);

        fonts = [
            { name: 'Source Sans 3', data: fontRegular, weight: 400, style: 'normal' },
            { name: 'Source Sans 3', data: fontBold, weight: 700, style: 'normal' },
            { name: 'Cormorant Garamond', data: fontDisplay, weight: 600, style: 'normal' },
        ];
    } catch (e) {
        console.log('⚠️  Custom fonts not found, downloading...');
        // Download fonts if not present
        await downloadFonts();

        const [fontRegular, fontBold, fontDisplay] = await Promise.all([
            readFile(fontRegularPath),
            readFile(fontBoldPath),
            readFile(fontDisplayPath),
        ]);

        fonts = [
            { name: 'Source Sans 3', data: fontRegular, weight: 400, style: 'normal' },
            { name: 'Source Sans 3', data: fontBold, weight: 700, style: 'normal' },
            { name: 'Cormorant Garamond', data: fontDisplay, weight: 600, style: 'normal' },
        ];
    }

    // Create the OG image using Satori
    const svg = await satori(
        {
            type: 'div',
            props: {
                style: {
                    width: '100%',
                    height: '100%',
                    display: 'flex',
                    flexDirection: 'column',
                    position: 'relative',
                },
                children: [
                    // Background image
                    {
                        type: 'img',
                        props: {
                            src: backgroundBase64,
                            style: {
                                position: 'absolute',
                                top: 0,
                                left: 0,
                                width: '100%',
                                height: '100%',
                                objectFit: 'cover',
                            },
                        },
                    },
                    // Gradient overlay
                    {
                        type: 'div',
                        props: {
                            style: {
                                position: 'absolute',
                                top: 0,
                                left: 0,
                                right: 0,
                                bottom: 0,
                                background: 'linear-gradient(135deg, rgba(26, 46, 26, 0.85) 0%, rgba(74, 103, 65, 0.75) 50%, rgba(26, 46, 26, 0.85) 100%)',
                            },
                        },
                    },
                    // Content container
                    {
                        type: 'div',
                        props: {
                            style: {
                                position: 'relative',
                                display: 'flex',
                                flexDirection: 'column',
                                justifyContent: 'center',
                                alignItems: 'flex-start',
                                height: '100%',
                                padding: '60px 80px',
                            },
                            children: [
                                // Badge
                                {
                                    type: 'div',
                                    props: {
                                        style: {
                                            display: 'flex',
                                            alignItems: 'center',
                                            gap: '10px',
                                            backgroundColor: 'rgba(125, 184, 158, 0.2)',
                                            border: '2px solid rgba(125, 184, 158, 0.4)',
                                            padding: '12px 24px',
                                            borderRadius: '50px',
                                            marginBottom: '28px',
                                        },
                                        children: [
                                            icon(STAR_PATH, 22),
                                            {
                                                type: 'span',
                                                props: {
                                                    style: {
                                                        color: colors.mint,
                                                        fontSize: '22px',
                                                        fontFamily: 'Source Sans 3',
                                                        fontWeight: 600,
                                                        letterSpacing: '0.05em',
                                                    },
                                                    children: 'PASTURE-RAISED MURRAY GREY BEEF',
                                                },
                                            },
                                        ],
                                    },
                                },
                                // Main heading
                                {
                                    type: 'div',
                                    props: {
                                        style: {
                                            fontFamily: 'Cormorant Garamond',
                                            fontSize: '96px',
                                            fontWeight: 600,
                                            color: colors.cream,
                                            lineHeight: 1.05,
                                            marginBottom: '24px',
                                            maxWidth: '950px',
                                        },
                                        children: 'Ferguson Livestock',
                                    },
                                },
                                // Subheading
                                {
                                    type: 'div',
                                    props: {
                                        style: {
                                            fontFamily: 'Source Sans 3',
                                            fontSize: '36px',
                                            fontWeight: 400,
                                            color: 'rgba(245, 242, 235, 0.9)',
                                            lineHeight: 1.35,
                                            maxWidth: '800px',
                                            marginBottom: '40px',
                                        },
                                        children: 'Premium beef boxes delivered direct from our family farm in Snake Valley, Victoria',
                                    },
                                },
                                // Features row
                                {
                                    type: 'div',
                                    props: {
                                        style: { display: 'flex', gap: '40px' },
                                        children: ['Pasture-raised', 'Murray Grey', 'Family farm', 'Local delivery'].map(feature),
                                    },
                                },
                            ],
                        },
                    },
                    // Website URL at bottom
                    {
                        type: 'div',
                        props: {
                            style: {
                                position: 'absolute',
                                bottom: '36px',
                                right: '70px',
                                display: 'flex',
                                alignItems: 'center',
                                gap: '12px',
                            },
                            children: [
                                {
                                    type: 'span',
                                    props: {
                                        style: {
                                            color: 'rgba(245, 242, 235, 0.8)',
                                            fontFamily: 'Source Sans 3',
                                            fontSize: '24px',
                                            fontWeight: 400,
                                        },
                                        children: 'fergusonlivestock.com.au',
                                    },
                                },
                            ],
                        },
                    },
                ],
            },
        },
        {
            width: WIDTH,
            height: HEIGHT,
            fonts,
        }
    );

    console.log('✅ SVG generated');

    // Convert SVG to PNG using resvg
    const resvg = new Resvg(svg, {
        fitTo: {
            mode: 'width',
            value: WIDTH,
        },
    });

    const pngData = resvg.render();
    const pngOutputBuffer = pngData.asPng();

    console.log('✅ PNG rendered');

    // Convert PNG to JPEG using sharp
    const jpegBuffer = await sharp(pngOutputBuffer)
        .jpeg({ quality: 90 })
        .toBuffer();

    // Save to public folder
    const outputPath = process.argv[2] ?? join(rootDir, 'public/og-image.jpg');
    await writeFile(outputPath, jpegBuffer);

    console.log(`✅ OG image saved to: ${outputPath}`);
    console.log(`📐 Dimensions: ${WIDTH}x${HEIGHT}px`);
}

async function downloadFonts() {
    const { mkdir } = await import('fs/promises');
    const fontsDir = join(rootDir, 'scripts/fonts');

    try {
        await mkdir(fontsDir, { recursive: true });
    } catch (e) {
        // Directory exists
    }

    // Use Google Fonts API to get font URLs
    // Inter is a reliable fallback font available via Google Fonts
    const fonts = [
        {
            // Source Sans 3 Regular from fontsource CDN
            url: `https://cdn.jsdelivr.net/fontsource/fonts/source-sans-3@${FONTSOURCE_VERSION}/latin-400-normal.ttf`,
            filename: 'SourceSans3-Regular.ttf',
        },
        {
            // Source Sans 3 Bold from fontsource CDN
            url: `https://cdn.jsdelivr.net/fontsource/fonts/source-sans-3@${FONTSOURCE_VERSION}/latin-700-normal.ttf`,
            filename: 'SourceSans3-Bold.ttf',
        },
        {
            // Cormorant Garamond from fontsource CDN
            url: `https://cdn.jsdelivr.net/fontsource/fonts/cormorant-garamond@${FONTSOURCE_VERSION}/latin-600-normal.ttf`,
            filename: 'CormorantGaramond-SemiBold.ttf',
        },
    ];

    for (const font of fonts) {
        console.log(`📥 Downloading ${font.filename}...`);
        const response = await fetch(font.url);
        if (!response.ok) {
            throw new Error(`Failed to download ${font.filename}: ${response.status}`);
        }
        const buffer = await response.arrayBuffer();
        await writeFile(join(fontsDir, font.filename), Buffer.from(buffer));
    }

    console.log('✅ Fonts downloaded');
}

// Run the generator
generateOGImage().catch(console.error);

