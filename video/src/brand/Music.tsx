import { Audio } from "@remotion/media";
import React from "react";
import {
  Easing,
  interpolate,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
} from "remotion";

/**
 * The soundtrack, with the two things a music bed always needs.
 *
 * **It is trimmed by the composition, not by the file.** A library track is
 * two or three minutes and a Short is twenty-seven seconds, so the audio is
 * cut wherever the video ends. Picking a `startFrom` matters more than picking
 * the track: most tracks spend their first eight bars arriving, and a Short
 * cannot afford eight bars of arriving.
 *
 * **It fades.** A hard stop on the last frame reads as a mistake — YouTube's
 * player does not fade for you, and an abrupt cut is the single most common
 * way a otherwise-finished video sounds unfinished.
 */
export const Music: React.FC<{
  /** File in `public/`, e.g. "music/track.mp3". */
  src: string;
  /** Where to start inside the track, in seconds — skip a slow intro. */
  startAtSeconds?: number;
  /** 0–1. With no voiceover there is nothing to duck under, so this can sit high. */
  volume?: number;
  fadeInFrames?: number;
  fadeOutFrames?: number;
}> = ({
  src,
  startAtSeconds = 0,
  volume = 0.7,
  fadeInFrames,
  fadeOutFrames,
}) => {
  const fadeIn = fadeInFrames ?? 20;
  const fadeOut = fadeOutFrames ?? 34;
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  return (
    <Audio
      src={staticFile(src)}
      trimBefore={Math.round(startAtSeconds * fps)}
      volume={interpolate(
        frame,
        [
          0,
          fadeIn,
          Math.max(fadeIn + 1, durationInFrames - fadeOut),
          durationInFrames,
        ],
        [0, volume, volume, 0],
        {
          extrapolateLeft: "clamp",
          extrapolateRight: "clamp",
          easing: Easing.bezier(0.4, 0, 0.6, 1),
        },
      )}
    />
  );
};
