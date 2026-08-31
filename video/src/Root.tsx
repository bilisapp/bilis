import React from "react";
import { Composition, Folder } from "remotion";
import "./index.css";
import { video } from "./brand";
import { OtelSetup, OTEL_DURATION } from "./otel/OtelSetup";
import { Correlate } from "./otel/scenes/Correlate";
import { Endpoint } from "./otel/scenes/Endpoint";
import { Install } from "./otel/scenes/Install";
import { Instrumented } from "./otel/scenes/Instrumented";
import { Outcome } from "./otel/scenes/Outcome";
import { Outro } from "./otel/scenes/Outro";
import { Production } from "./otel/scenes/Production";
import { Signals } from "./otel/scenes/Signals";
import { Title } from "./otel/scenes/Title";
import {
  CtaPortrait,
  FourLinesPortrait,
  FreePortrait,
  HookPortrait,
  OneCommandPortrait,
  OtelShort,
  SHORT_DURATION,
} from "./otel-short/OtelShort";

/**
 * Each scene is registered on its own as well as inside the video, so
 * double-clicking a sequence in the timeline jumps straight to it and a scene
 * can be re-timed without scrubbing the whole thing.
 */
export const RemotionRoot: React.FC = () => {
  return (
    <>
      <Composition
        id="OtelSetup"
        component={OtelSetup}
        durationInFrames={OTEL_DURATION}
        fps={video.fps}
        width={video.width}
        height={video.height}
      />

      <Composition
        id="OtelShort"
        component={OtelShort}
        durationInFrames={SHORT_DURATION}
        fps={30}
        width={1080}
        height={1920}
      />

      <Folder name="OtelShort-Scenes">
        <Composition
          id="Short-Hook"
          component={HookPortrait}
          durationInFrames={180}
          fps={30}
          width={1080}
          height={1920}
        />
        <Composition
          id="Short-OneCommand"
          component={OneCommandPortrait}
          durationInFrames={210}
          fps={30}
          width={1080}
          height={1920}
        />
        <Composition
          id="Short-FourLines"
          component={FourLinesPortrait}
          durationInFrames={300}
          fps={30}
          width={1080}
          height={1920}
        />
        <Composition
          id="Short-Free"
          component={FreePortrait}
          durationInFrames={240}
          fps={30}
          width={1080}
          height={1920}
        />
        <Composition
          id="Short-Cta"
          component={CtaPortrait}
          durationInFrames={180}
          fps={30}
          width={1080}
          height={1920}
        />
      </Folder>

      <Folder name="OtelSetup-Scenes">
        <Composition
          id="Scene-Title"
          component={Title}
          durationInFrames={150}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Outcome"
          component={Outcome}
          durationInFrames={270}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Install"
          component={Install}
          durationInFrames={270}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Endpoint"
          component={Endpoint}
          durationInFrames={300}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Instrumented"
          component={Instrumented}
          durationInFrames={300}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Signals"
          component={Signals}
          durationInFrames={300}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Production"
          component={Production}
          durationInFrames={300}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Correlate"
          component={Correlate}
          durationInFrames={300}
          fps={30}
          width={1920}
          height={1080}
        />
        <Composition
          id="Scene-Outro"
          component={Outro}
          durationInFrames={270}
          fps={30}
          width={1920}
          height={1080}
        />
      </Folder>
    </>
  );
};
