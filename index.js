const express = require('express');
const cors = require('cors');
const fetch = require('node-fetch');
const app = express();

app.use(cors()); // Permite que InfinityFree haga peticiones aquí
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

app.post('/', async (req, res) => {
    // Detectamos si viene como FormData (de tu script actual) o JSON
    const message = req.body.message;
    const historyRaw = req.body.history || '[]';
    
    let history = [];
    try {
        history = typeof historyRaw === 'string' ? JSON.parse(historyRaw) : historyRaw;
    } catch (e) { history = []; }

    const apiKey = process.env.ANTHROPIC_API_KEY;

    if (!message) {
        return res.status(400).json({ success: false, message: "No message provided" });
    }

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
                system: "Eres un asistente de IA para WebBridge Solutions, empresa de desarrollo web en Puebla.",
                messages: history.concat([{ role: 'user', content: message }])
            })
        });

        const data = await response.json();
        
        if (data.content && data.content[0]) {
            res.json({ success: true, message: data.content[0].text });
        } else {
            console.error("Error de Anthropic:", data);
            res.status(500).json({ success: false, message: "Error en la respuesta de la IA" });
        }
    } catch (error) {
        console.error("Error de servidor:", error);
        res.status(500).json({ success: false, message: error.message });
    }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`Servidor WebBridge corriendo en puerto ${PORT}`));
