const express = require('express');
const cors = require('cors');
const fetch = require('node-fetch'); // Render maneja esto
const app = express();

app.use(cors());
app.use(express.urlencoded({ extended: true }));
app.use(express.json());

app.post('/', async (req, res) => {
    const { message, history } = req.body;
    const apiKey = process.env.ANTHROPIC_API_KEY;

    try {
        const response = await fetch('https://api.anthropic.com/v1/messages', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'x-api-key': apiKey,
                'anthropic-version': '2023-06-01'
            },
            body: JSON.stringify({
                model: "claude-3-5-sonnet-20240620",
                max_tokens: 1024,
                system: "Eres un asistente de IA para WebBridge Solutions.",
                messages: JSON.parse(history || '[]').concat([{ role: 'user', content: message }])
            })
        });

        const data = await response.json();
        res.json({ success: true, message: data.content[0].text });
    } catch (error) {
        res.json({ success: false, error: error.message });
    }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`Server running on port ${PORT}`));
