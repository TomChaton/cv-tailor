# Recorded LLM responses

`FakeLlmClient` answers from these. One file per recorded response, named by
the key a test asks for.

Each file holds the model, the stop reason, the four token counts and the
response text.

**The model in a fixture is the one the provider reports**, as in a real
response: it may be a dated build of the alias that was asked for, and
`extraction-dated-model.json` is one that is. Cost is not recorded, and it is
never computed from this name — the fake prices a live call from the model its
purpose is configured with, exactly as the real client prices from the model
the request asked for, so a rate change moves the two together.

Every fixture is written against the fictional master CV. Nothing here derives
from a real one.
