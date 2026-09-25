import { TransitionSeries, linearTiming } from "@remotion/transitions";
import { fade } from "@remotion/transitions/fade";
import React from "react";
import { AbsoluteFill } from "remotion";
import { Music } from "../brand";
import { SOUNDTRACK } from "../soundtrack";
import { Ask, Cause, Cta, Early, Fleet, Hook, Meet, Open, Pager, Why } from "./scenes";

/**
 * The metrics launch film: 1920×1080, about fifty seconds, told as one
 * incident — the pain, then the benefits in the order you would need them,
 * then the ask.
 *
 * The product-film register — depth, a slow camera, panels in space, type
 * rising out of a mask — over the same brand as everything else: achromatic
 * chrome, colour only on data. One transition, the 12-frame crossfade the
 * guide prescribes, so the eye is never asked to track a wipe while reading.
 */
const FADE = 12;

export const METRICS_LAUNCH_DURATION = 165 + 135 + 195 + 120 + 180 + 195 + 180 + 180 + 120 + 165 - 9 * FADE;

export const MetricsLaunch: React.FC = () => {
  const music = SOUNDTRACK.MetricsLaunch;
  const crossfade = <TransitionSeries.Transition presentation={fade()} timing={linearTiming({ durationInFrames: FADE })} />;

  return (
    <AbsoluteFill>
      <TransitionSeries>
        <TransitionSeries.Sequence durationInFrames={165} name="Hook · 3:07 AM">
          <Hook />
        </TransitionSeries.Sequence>
        {crossfade}
        <TransitionSeries.Sequence durationInFrames={135} name="The logs">
          <Pager />
        </TransitionSeries.Sequence>
        {crossfade}
        <TransitionSeries.Sequence durationInFrames={195} name="Meet Bilis">
          <Meet />
        </TransitionSeries.Sequence>
        {crossfade}
        <TransitionSeries.Sequence durationInFrames={120} name="Why metrics">
          <Why />
        </TransitionSeries.Sequence>
        {crossfade}
        <TransitionSeries.Sequence durationInFrames={180} name="See it coming">
          <Early />
        </TransitionSeries.Sequence>
        {crossfade}
        <TransitionSeries.Sequence durationInFrames={195} name="Root cause">
          <Cause />
        </TransitionSeries.Sequence>
        {crossfade}
        <TransitionSeries.Sequence durationInFrames={180} name="Every server">
          <Fleet />
        </TransitionSeries.Sequence>
        {crossfade}
        <TransitionSeries.Sequence durationInFrames={180} name="Ask">
          <Ask />
        </TransitionSeries.Sequence>
        {crossfade}
        <TransitionSeries.Sequence durationInFrames={120} name="No lock-in">
          <Open />
        </TransitionSeries.Sequence>
        {crossfade}
        <TransitionSeries.Sequence durationInFrames={165} name="Stop guessing">
          <Cta />
        </TransitionSeries.Sequence>
      </TransitionSeries>

      {music ? (
        <Music
          src={music.src}
          startAtSeconds={music.startAtSeconds}
          volume={music.volume}
          fadeInFrames={music.fadeInFrames}
          fadeOutFrames={music.fadeOutFrames}
        />
      ) : null}
    </AbsoluteFill>
  );
};
