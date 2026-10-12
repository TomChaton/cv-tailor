# Recorded LLM responses

`FakeLlmClient` answers from these. One file per recorded response, named by
the key a test asks for.

Each file holds the model, the stop reason, the four token counts and the
response text. Cost is not recorded: it is computed from the configured rates
for the fixture's model, so a rate change moves the fake and the real client
together.

Every fixture is written against the fictional master CV. Nothing here derives
from a real one.
