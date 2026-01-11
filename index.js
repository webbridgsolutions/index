const express = require('express');
const cors = require('cors');
const fetch = require('node-fetch');
const app = express();

app.use(cors());
app.use(express.json());

app.post('/', async (req, res) => {
    const { message, history } = req.body;
    const apiKey = process.env.ANTHROPIC_API_KEY;

    if (!apiKey) {
        return res.status(500).json({ success: false, message: "Falta la API Key en el servidor" });
    }

    try {
        // Formatear el historial correctamente para Anthropic
        const formattedMessages = (history || [])
            .filter(msg => msg.role === 'user' || msg.role === 'assistant')
            .map(msg => ({
                role: msg.role,
                content: msg.content
            }));

        // Agregar el mensaje actual del usuario
        formattedMessages.push({ role: 'user', content: message });

        const response = await fetch('https://api.anthropic.com/v1/messages', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'x-api-key': apiKey,
                'anthropic-version': '2023-06-01'
            },
            body: JSON.stringify({
                model: "claude-3-5-sonnet-20241022",
                max_tokens: 1024,
                system: "Eres un asistente de IA para WebBridge Solutions en Puebla, México.",
                messages: formattedMessages
            })
        });

        const data = await response.json();

        if (response.ok && data.content) {
            res.json({ success: true, message: data.content[0].text });
        } else {
            console.error("Error de Anthropic API:", data);
            res.status(response.status).json({ success: false, message: data.error?.message || "Error en la IA" });
        }
    } catch (error) {
        console.error("Error en el servidor de Render:", error);
        res.status(500).json({ success: false, message: error.message });
    }
});

const PORT = process.env.PORT || 10000;
app.listen(PORT, () => console.log(`Servidor listo en puerto ${PORT}`));
