import { linearTiming, TransitionSeries } from "@remotion/transitions";
import { fade } from "@remotion/transitions/fade";
import React from "react";
import { AbsoluteFill } from "remotion";
import { Music } from "../brand";
import { SOUNDTRACK } from "../soundtrack";
import { Correlate } from "./scenes/Correlate";
import { Endpoint } from "./scenes/Endpoint";
import { Install } from "./scenes/Install";
import { Instrumented } from "./scenes/Instrumented";
import { Outcome } from "./scenes/Outcome";
import { Outro } from "./scenes/Outro";
import { Production } from "./scenes/Production";
import { Signals } from "./scenes/Signals";
import { Title } from "./scenes/Title";

/**
 * Every cut is a plain 12-frame crossfade.
 *
 * A tutorial is read, not watched, and a wipe or a flip between two code panels
 * makes the viewer track motion at exactly the moment they should be reading a
 * filename. The template has one transition on purpose.
 */
const CUT = 12;

/** Scene lengths in frames at 30fps. Inline so they stay draggable in Studio. */
export const OTEL_SCENES = [
  { id: "Title", durationInFrames: 150 },
  { id: "Outcome", durationInFrames: 270 },
  { id: "Install", durationInFrames: 270 },
  { id: "Endpoint", durationInFrames: 300 },
  { id: "Instrumented", durationInFrames: 300 },
  { id: "Signals", durationInFrames: 300 },
  { id: "Production", durationInFrames: 300 },
  { id: "Correlate", durationInFrames: 300 },
  { id: "Outro", durationInFrames: 270 },
] as const;

/** Sum of the scenes, less one crossfade per cut. */
export const OTEL_DURATION =
  OTEL_SCENES.reduce((total, scene) => total + scene.durationInFrames, 0) -
  CUT * (OTEL_SCENES.length - 1);

export const OtelSetup: React.FC = () => {
  const music = SOUNDTRACK.OtelSetup;

  return (
    <AbsoluteFill>
    <TransitionSeries>
      <TransitionSeries.Sequence durationInFrames={150} name="Title">
        <Title />
      </TransitionSeries.Sequence>
      <TransitionSeries.Transition
        presentation={fade()}
        timing={linearTiming({ durationInFrames: CUT })}
      />

      <TransitionSeries.Sequence durationInFrames={270} name="What you get">
        <Outcome />
      </TransitionSeries.Sequence>
      <TransitionSeries.Transition
        presentation={fade()}
        timing={linearTiming({ durationInFrames: CUT })}
      />

      <TransitionSeries.Sequence durationInFrames={270} name="01 Install">
        <Install />
      </TransitionSeries.Sequence>
      <TransitionSeries.Transition
        presentation={fade()}
        timing={linearTiming({ durationInFrames: CUT })}
      />

      <TransitionSeries.Sequence durationInFrames={300} name="02 Endpoint">
        <Endpoint />
      </TransitionSeries.Sequence>
      <TransitionSeries.Transition
        presentation={fade()}
        timing={linearTiming({ durationInFrames: CUT })}
      />

      <TransitionSeries.Sequence durationInFrames={300} name="03 Instrumented">
        <Instrumented />
      </TransitionSeries.Sequence>
      <TransitionSeries.Transition
        presentation={fade()}
        timing={linearTiming({ durationInFrames: CUT })}
      />

      <TransitionSeries.Sequence durationInFrames={300} name="04 Signals">
        <Signals />
      </TransitionSeries.Sequence>
      <TransitionSeries.Transition
        presentation={fade()}
        timing={linearTiming({ durationInFrames: CUT })}
      />

      <TransitionSeries.Sequence durationInFrames={300} name="05 Production">
        <Production />
      </TransitionSeries.Sequence>
      <TransitionSeries.Transition
        presentation={fade()}
        timing={linearTiming({ durationInFrames: CUT })}
      />

      <TransitionSeries.Sequence durationInFrames={300} name="06 Correlate">
        <Correlate />
      </TransitionSeries.Sequence>
      <TransitionSeries.Transition
        presentation={fade()}
        timing={linearTiming({ durationInFrames: CUT })}
      />

      <TransitionSeries.Sequence durationInFrames={270} name="Outro">
        <Outro />
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
