/**
 * Encode audio as a 16-bit PCM WAV file.
 *
 * @param channels One array of samples from -1 to 1 per channel, all the same length.
 */
export function encodeWav(
    channels: Float32Array[],
    sampleRate: number,
): ArrayBuffer {
    const frames = channels[0]?.length ?? 0;
    const blockAlign = channels.length * 2;
    const buffer = new ArrayBuffer(44 + frames * blockAlign);
    const view = new DataView(buffer);

    const text = (offset: number, value: string) => {
        for (let i = 0; i < value.length; i++) {
            view.setUint8(offset + i, value.charCodeAt(i));
        }
    };

    text(0, 'RIFF');
    view.setUint32(4, 36 + frames * blockAlign, true);
    text(8, 'WAVE');
    text(12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true); // PCM
    view.setUint16(22, channels.length, true);
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, sampleRate * blockAlign, true);
    view.setUint16(32, blockAlign, true);
    view.setUint16(34, 16, true);
    text(36, 'data');
    view.setUint32(40, frames * blockAlign, true);

    // Loud output is scaled down rather than clipped.
    let peak = 1;
    for (const channel of channels) {
        for (let i = 0; i < channel.length; i++) {
            peak = Math.max(peak, Math.abs(channel[i]));
        }
    }

    let offset = 44;
    for (let frame = 0; frame < frames; frame++) {
        for (const channel of channels) {
            const sample = Math.max(-1, Math.min(1, channel[frame] / peak));
            view.setInt16(offset, Math.round(sample * 32767), true);
            offset += 2;
        }
    }

    return buffer;
}
