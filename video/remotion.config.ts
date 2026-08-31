/**
 * Note: When using the Node.JS APIs, the config file
 * doesn't apply. Instead, pass options directly to the APIs.
 *
 * All configuration options: https://remotion.dev/docs/config
 */

import { Config } from "@remotion/cli/config";
import { enableTailwind } from '@remotion/tailwind-v4';

Config.setRspack(true);
/*
 * PNG rather than JPEG frames, for two reasons that both matter here.
 *
 * JPEG frames make the encoder tag the stream full-range (yuvj420p), and a
 * player that ignores the range flag then lifts the blacks — on a video that
 * is almost entirely one dark surface, that reads as a washed-out background.
 * PNG also keeps small text and 1px borders crisp instead of ringing them.
 */
Config.setVideoImageFormat("png");
Config.setPixelFormat("yuv420p");
Config.setOverwriteOutput(true);
Config.overrideBundlerConfig(enableTailwind);
