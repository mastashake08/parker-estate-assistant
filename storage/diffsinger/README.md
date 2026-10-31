# DiffSinger voices

This folder is mounted into the `diffsinger` service as DiffSinger's `checkpoints` folder. Put your voices here:

```
storage/diffsinger/
├── voices.json                      # which experiments make up each voice (copy voices.example.json)
├── my_voice_acoustic/               # an acoustic experiment: config.yaml, model_ckpt_steps_*.ckpt, spk_map.json
├── my_voice_variance/               # optional variance experiment, which predicts timing and pitch
└── pc_nsf_hifigan_44.1k_hop512_128bin_2025.02/   # the vocoder named by vocoder_ckpt in the acoustic config
```

Each voice in `voices.json` has:

- `name`: shown in the audio studio.
- `acoustic`: the acoustic experiment's folder name. Required.
- `variance`: the variance experiment's folder name, or `null`. With one, DiffSinger predicts natural phoneme timing and pitch from the notes. Without one, the voice sings the app's estimates as written.
- `language`: the dictionary to use, such as `en`, `zh` or `ja`. Defaults to the first one in the acoustic config's `dictionaries`.
- `speaker`: for multi-speaker voices, the speaker to use by default.
- `vowels`: the phonemes that are vowels. Usually not needed: vowels are worked out from the phoneme names. List them if lyrics come out with consonants in the wrong place.

Dictionaries are read from the paths in the acoustic experiment's `config.yaml` (`dictionaries:`), relative to the DiffSinger folder. Put them in this folder and point to them as `checkpoints/...`.

Without `voices.json`, every acoustic experiment here is offered as a voice without a variance model.
