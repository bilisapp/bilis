# Drop a track here

From the YouTube Audio Library: studio.youtube.com → **Music** (left sidebar).
Download an `.mp3`, put it in this folder, then point `src/soundtrack.ts` at it:

```ts
OtelShortPunchy: {
  src: "music/your-track.mp3",
  startAtSeconds: 12,
  volume: 0.75,
  attribution: null,
},
```

The composition trims the track to its own length and fades both ends, so the
file can be any duration — you only choose *where in the track* to start.

If the library says the track requires attribution, paste its exact credit line
into `attribution` here and into the video description. The licence only holds
if the credit ships with the video.
