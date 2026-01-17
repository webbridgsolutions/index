const express = require('express');
const cors = require('cors');
const fetch = require('node-fetch');
const app = express();

const systemPrompt = `
Eres el asistente virtual EXCLUSIVO de WebBridge Solutions. 
Tu misión es convertir visitantes en clientes informados.

REGLAS CRÍTICAS:
1. FOCO: Solo hablas de WebBridge Solutions.
2. MOTIVACIÓN: Si piden frases motivadoras, dales una sobre éxito digital o resiliencia empresarial. 
3. CIERRE: Siempre termina con un CTA (Call to Action). Ejemplo: "...¿Te gustaría agendar una asesoría gratuita?"
4. RESTRICCIÓN: Si preguntan de temas ajenos, di: "Lo siento, como asistente de WebBridge Solutions, mi especialidad es ayudarte a digitalizar tu negocio. ¿Hablamos de tu próxima web?"
5. FORMATO: Usa emojis, negritas en los precios y saltos de línea para que sea visualmente atractivo.
6. CIERRE DE VENTA: Si el usuario muestra interés real, indícale que puede iniciar el proceso de cotización aquí mismo proporcionando sus datos.

BASE DE CONOCIMIENTO:
- Empresa: WebBridge Solutions (Puebla, México).
- Servicios: Desarrollo a medida (No plantillas), E-commerce, ERP/CRM, Chatbots IA, Recorridos 3D, AR.
- Precios: WebStart Básico $4,000, WebPro Intermedio $5,500, WebCorp Empresarial $8,000, WebElite Avanzado $10,000, WebShop E-Commerce $15,000, Recorridos virtuales 3D $20,000, AR Vision 360 $25,000.
- Contacto: WhatsApp 2761334864 | webbridgsolucions@gmail.com
- Web: https://web-bridge-solutions.wuaze.com/

TONO: Profesional, amigable y con la calidez poblana.
`;

app.use(cors({
    origin: '*',
    methods: ['POST', 'GET', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization', 'X-Requested-With']
}));

app.use(express.json());
app.use(express.urlencoded({ extended: true }));

app.options('*', cors());

app.post('/', async (req, res) => {
    const { message, history } = req.body;
    const apiKey = process.env.GROQ_API_KEY; 

    try {
        // Corrección: Limpieza profunda del historial para Groq
        let rawHistory = typeof history === 'string' ? JSON.parse(history) : (history || []);
        let parsedHistory = rawHistory.map(msg => ({
            role: msg.role === 'assistant' ? 'assistant' : 'user',
            content: msg.content
        })).filter(msg => msg.content);

        const response = await fetch('https://api.groq.com/openai/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${apiKey}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                model: "llama-3.3-70b-versatile", 
                messages: [
                    { role: "system", content: systemPrompt },
                    ...parsedHistory,
                    { role: "user", content: message }
                ],
                stream: false 
            })
        });

        const data = await response.json();
        if (data.choices && data.choices[0]) {
            res.json({ success: true, message: data.choices[0].message.content });
        } else {
            throw new Error("Respuesta inválida de Groq");
        }

    } catch (error) {
        console.error("Error en Render:", error.message);
        res.status(500).json({ success: false, message: error.message });
    }
});

const PORT = process.env.PORT || 10000;
app.listen(PORT, () => console.log(`Servidor Groq WebBridge listo`));
