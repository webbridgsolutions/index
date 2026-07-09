<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;

/**
 * ChatbotControllerGroq v3
 * ------------------------
 * Cadena de respuesta:
 *   1. Groq Cloud API directo (rápido, usa GROQ_API_KEY del .env)
 *   2. Proxy en Render (respaldo si Groq falla)
 *   3. Fallback local con respuestas predefinidas
 *
 * El conocimiento del asistente vive en getWebBridgeKnowledge()
 * — edítalo ahí cuando cambien precios, proyectos o servicios.
 */
class ChatbotControllerGroq extends ResourceController
{
    protected $format = 'json';

    private string $groqUrl   = 'https://api.groq.com/openai/v1/chat/completions';
    private string $groqModel = 'llama-3.3-70b-versatile';
    private string $renderUrl = 'https://webbridge-ai.onrender.com';

    public function message()
    {
        // HABILITAR HEADERS CORS
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit;
        }

        try {
            $userMessage = $this->request->getPost('message');
            $historyRaw  = $this->request->getPost('history');
            $conversationHistory = json_decode($historyRaw ?? '[]', true) ?: [];

            if (empty($userMessage)) {
                return $this->respond(['success' => false, 'error' => 'Mensaje vacío'], 400);
            }

            // 1) Groq directo
            $response = $this->callGroq($userMessage, $conversationHistory);

            // 2) Respaldo: proxy en Render
            if (!$response['success']) {
                log_message('warning', 'Groq directo falló, intentando Render: ' . ($response['error'] ?? '?'));
                $response = $this->callRenderProxy($userMessage, $conversationHistory);
            }

            if ($response['success']) {
                return $this->respond([
                    'success'   => true,
                    'message'   => $response['message'],
                    'mode'      => $response['mode'] ?? 'ai',
                    'timestamp' => date('Y-m-d H:i:s'),
                ]);
            }

            // 3) Fallback local
            log_message('warning', 'IA no disponible, usando fallback local: ' . ($response['error'] ?? 'Unknown'));
            return $this->respond([
                'success'   => true,
                'message'   => $this->getSmartFallbackResponse($userMessage),
                'mode'      => 'fallback_local',
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Error en controlador: ' . $e->getMessage());
            return $this->respond([
                'success'   => true,
                'message'   => $this->getSmartFallbackResponse($userMessage ?? ''),
                'mode'      => 'fallback_exception',
                'timestamp' => date('Y-m-d H:i:s'),
            ], 200);
        }
    }

    /**
     * Llamada directa a Groq Cloud API con el conocimiento actualizado.
     */
    private function callGroq(string $userMessage, array $history): array
    {
        $apiKey = trim((string) (env('groq.apiKey') ?: env('GROQ_API_KEY') ?: ''));
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'GROQ_API_KEY no configurada'];
        }

        // Armar mensajes: system + últimos 10 turnos + mensaje actual
        $messages = [['role' => 'system', 'content' => $this->getWebBridgeKnowledge()]];

        $history = array_slice($history, -10);
        foreach ($history as $turn) {
            $role    = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($turn['content'] ?? ''));
            if ($content !== '') {
                $messages[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
            }
        }
        $messages[] = ['role' => 'user', 'content' => mb_substr($userMessage, 0, 2000)];

        $payload = [
            'model'       => $this->groqModel,
            'temperature' => 0.6,
            'max_tokens'  => 900,
            'messages'    => $messages,
        ];

        $ch = curl_init($this->groqUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpCode !== 200) {
            return ['success' => false, 'error' => "Groq HTTP {$httpCode}: {$curlErr}"];
        }

        $data    = json_decode($result, true);
        $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

        if ($content === '') {
            return ['success' => false, 'error' => 'Respuesta vacía de Groq'];
        }

        return ['success' => true, 'message' => $content, 'mode' => 'ai_groq'];
    }

    /**
     * Enviar formulario de cotización o cita
     */
    public function sendQuoteRequest()
    {
        // CORS
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit;
        }

        try {
            $json = $this->request->getJSON(true);

            $nombre   = $json['nombre'] ?? '';
            $email    = $json['email'] ?? '';
            $telefono = $json['telefono'] ?? '';
            $tipo     = $json['tipo'] ?? 'cotización';
            $detalles = $json['detalles'] ?? '';

            if (empty($nombre) || empty($email)) {
                return $this->respond([
                    'success' => false,
                    'error'   => 'Nombre y email son obligatorios'
                ], 400);
            }

            $emailSent = $this->sendEmail($nombre, $email, $telefono, $tipo, $detalles);

            if ($emailSent) {
                return $this->respond([
                    'success' => true,
                    'message' => '¡Solicitud enviada correctamente! Te contactaremos pronto.'
                ]);
            }

            return $this->respond([
                'success' => false,
                'error'   => 'Error al enviar el email'
            ], 500);

        } catch (\Exception $e) {
            log_message('error', 'Error en sendQuoteRequest: ' . $e->getMessage());
            return $this->respond([
                'success' => false,
                'error'   => 'Error del servidor'
            ], 500);
        }
    }

    /**
     * Enviar email con la solicitud
     */
    private function sendEmail($nombre, $email, $telefono, $tipo, $detalles)
    {
        try {
            $emailService = \Config\Services::email();

            $emailService->setFrom('webbridgsolucions@gmail.com', 'WebBridge AI Chatbot');
            $emailService->setTo('webbridgsolucions@gmail.com');
            $emailService->setSubject('Nueva Solicitud de ' . ucfirst($tipo) . ' desde Chatbot');

            $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #0f2a5e 0%, #1e3a8a 55%, #2d4fad 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; }
                    .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
                    .field { margin-bottom: 15px; padding: 10px; background: white; border-radius: 5px; }
                    .field strong { color: #0f2a5e; }
                    .footer { text-align: center; margin-top: 20px; color: #6b7280; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>🤖 Nueva Solicitud desde WebBridge AI</h2>
                    </div>
                    <div class='content'>
                        <p>Has recibido una nueva solicitud de <strong>" . ucfirst($tipo) . "</strong> a través del chatbot:</p>

                        <div class='field'>
                            <strong>👤 Nombre:</strong><br>
                            " . htmlspecialchars($nombre) . "
                        </div>

                        <div class='field'>
                            <strong>📧 Email:</strong><br>
                            <a href='mailto:" . htmlspecialchars($email) . "'>" . htmlspecialchars($email) . "</a>
                        </div>

                        <div class='field'>
                            <strong>📞 Teléfono:</strong><br>
                            " . (!empty($telefono) ? htmlspecialchars($telefono) : 'No proporcionado') . "
                        </div>

                        <div class='field'>
                            <strong>📝 Tipo de Solicitud:</strong><br>
                            " . ucfirst($tipo) . "
                        </div>

                        <div class='field'>
                            <strong>💬 Detalles:</strong><br>
                            " . nl2br(htmlspecialchars($detalles)) . "
                        </div>

                        <div class='footer'>
                            <p>Este mensaje fue generado automáticamente por WebBridge AI Chatbot</p>
                            <p>📅 " . date('d/m/Y H:i:s') . "</p>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            ";

            $emailService->setMessage($message);

            if ($emailService->send()) {
                log_message('info', 'Email enviado correctamente a webbridgsolucions@gmail.com');
                return true;
            }

            log_message('error', 'Error al enviar email: ' . $emailService->printDebugger(['headers']));
            return false;

        } catch (\Exception $e) {
            log_message('error', 'Excepción al enviar email: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Respaldo: proxy en Render (puede tardar si el servicio está dormido)
     */
    private function callRenderProxy(string $userMessage, array $conversationHistory): array
    {
        try {
            $data = [
                'message' => $userMessage,
                'history' => $conversationHistory
            ];

            $ch = curl_init($this->renderUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($data),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $result   = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($result === false) {
                return ['success' => false, 'error' => $error];
            }

            $response = json_decode($result, true);

            if ($httpCode === 200 && isset($response['success']) && $response['success'] === true) {
                return ['success' => true, 'message' => $response['message'], 'mode' => 'ai_render'];
            }

            return ['success' => false, 'error' => 'Status Code: ' . $httpCode];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function getSmartFallbackResponse(string $message): string
    {
        $lowerMessage = strtolower($message);
        $lowerMessage = $this->removeAccents($lowerMessage);

        // PROYECTOS REALIZADOS
        if (preg_match('/(proyecto|portafolio|ejemplo|trabajo|plateria|cafeteria|dies|cca|campus)/i', $lowerMessage)) {
            return "💼 **Proyectos Destacados de WebBridge Solutions:**\n\n" .
                   "**1. Platería Futura** 💎\n" .
                   "• E-commerce de joyería de plata\n" .
                   "• Catálogo interactivo y carrito de compras\n" .
                   "• Pagos en línea seguros con Stripe\n\n" .
                   "**2. CCA Campus** 🎓\n" .
                   "• Plataforma e-learning para capacitación automotriz\n" .
                   "• Cursos en video, control de acceso de alumnos\n" .
                   "• Pagos con tarjeta y transferencia bancaria\n\n" .
                   "**3. Cafetería Universitaria** ☕\n" .
                   "• Sistema de gestión con menú digital\n" .
                   "• Chatbot con IA para pedidos automáticos\n" .
                   "• Panel de administración de ventas\n\n" .
                   "**4. DIES Seguridad** 🔒\n" .
                   "• Catálogo digital de cajas fuertes\n" .
                   "• Chatbot de atención integrado\n\n" .
                   "**5. BridLux POS** 🧾 (producto propio)\n" .
                   "• Punto de venta híbrido: local o en la nube\n\n" .
                   "**¿Te interesa algo similar para tu negocio?** 🚀";
        }

        // COTIZACIÓN / CITA
        if (preg_match('/(cotiz|presupuesto|agendar|cita|reunion|precio proyecto)/i', $lowerMessage)) {
            return "📋 **Solicitar Cotización o Agendar Cita**\n\n" .
                   "¡Excelente! Me encantaría ayudarte.\n\n" .
                   "Para darte la mejor atención, necesito algunos datos:\n\n" .
                   "**👤 Tu nombre**\n" .
                   "**📧 Tu email**\n" .
                   "**📞 Tu teléfono** (opcional)\n" .
                   "**💬 Cuéntame sobre tu proyecto**\n\n" .
                   "Por favor, proporciona esta información y te contactaremos en menos de 24 horas. 🚀\n\n" .
                   "_Presiona el botón 'Solicitar Cotización' que aparecerá abajo._";
        }

        // SOBRE LA EMPRESA
        if (preg_match('/(que es|quienes son|sobre|acerca de).*(webbridge|empresa)/i', $lowerMessage)) {
            return "🏢 **WebBridge Solutions** es una empresa de desarrollo web profesional en Puebla, México.\n\n" .
                   "**Nos especializamos en:**\n" .
                   "• Desarrollo web desde cero (NO plantillas)\n" .
                   "• Sistemas de gestión y puntos de venta\n" .
                   "• E-commerce y plataformas e-learning\n" .
                   "• Chatbots con IA\n\n" .
                   "¿Te gustaría conocer nuestros paquetes?";
        }

        // UBICACIÓN
        if (preg_match('/(donde|ubicacion|direccion|oficina)/i', $lowerMessage)) {
            return "📍 **Ubicación:**\n\n" .
                   "Puebla, México\n\n" .
                   "**Contacto:**\n" .
                   "📞 2761334864 (WhatsApp)\n" .
                   "📧 webbridgsolucions@gmail.com\n" .
                   "⏰ Lun-Vie 8:00 AM - 6:00 PM";
        }

        // TIEMPOS
        if (preg_match('/(cuanto tiempo|plazo|entrega|duracion)/i', $lowerMessage)) {
            return "⏱️ **Tiempos de Desarrollo:**\n\n" .
                   "• WebStart: 1-2 semanas\n" .
                   "• WebPro: 2-3 semanas\n" .
                   "• WebCorp: 3-4 semanas\n" .
                   "• WebElite: 4-6 semanas\n" .
                   "• WebShop: 6-8 semanas\n" .
                   "• Sistemas a medida: según alcance\n\n" .
                   "Siempre te damos un cronograma claro desde el inicio. ¿Tienes fecha límite?";
        }

        // BRIDLUX POS
        if (preg_match('/(bridlux|punto de venta|pos|caja)/i', $lowerMessage)) {
            return "🧾 **BridLux — Punto de Venta**\n\n" .
                   "Nuestro sistema POS híbrido para negocios:\n\n" .
                   "1. **BridLux Local UNICAJA** - \$7,800 MXN (pago único, hardware incluido)\n" .
                   "2. **BridLux Local PRO** - \$10,100 MXN (pago único, mayor potencia)\n" .
                   "3. **BridLux en la Nube** - \$900 MXN/mes (acceso desde cualquier lugar)\n\n" .
                   "Procesa ventas, controla inventario en tiempo real y genera reportes. ¿Quieres una demostración?";
        }

        // PRECIOS Y PAQUETES
        if (preg_match('/(paquete|precio|costo|cuanto cuesta)/i', $lowerMessage)) {
            return "📦 **Paquetes WebBridge:**\n\n" .
                   "1. **WebStart** - \$4,000 MXN\n" .
                   "2. **WebPro** - \$5,500 MXN\n" .
                   "3. **WebCorp** - \$8,000 MXN\n" .
                   "4. **WebElite** ⭐ - \$10,000 MXN\n" .
                   "5. **WebShop** - \$15,000 MXN\n\n" .
                   "**Punto de venta BridLux:**\n" .
                   "• Local desde \$7,800 · Nube \$900/mes\n\n" .
                   "Todos los paquetes web incluyen dominio y hosting 1 año. ¿Cuál te interesa?";
        }

        // CONTACTO
        if (preg_match('/(contacto|telefono|whatsapp|email|llamar)/i', $lowerMessage)) {
            return "📞 **Contacto:**\n\n" .
                   "**WhatsApp:** 2761334864\n" .
                   "**Email:** webbridgsolucions@gmail.com\n" .
                   "**Horario:** Lun-Vie 8AM-6PM\n\n" .
                   "¡Respuesta en menos de 24h!";
        }

        // SERVICIOS
        if (preg_match('/(servicio|que hacen|ofrecen)/i', $lowerMessage)) {
            return "🚀 **Servicios:**\n\n" .
                   "• Desarrollo web profesional\n" .
                   "• Sistemas empresariales (CRM/ERP)\n" .
                   "• Puntos de venta (BridLux POS)\n" .
                   "• E-commerce completo\n" .
                   "• Plataformas e-learning\n" .
                   "• Chatbots con IA\n\n" .
                   "¿Qué necesitas?";
        }

        // SALUDOS
        if (preg_match('/^(hola|hello|hi|buenos|hey)/', $lowerMessage)) {
            return "¡Hola! 👋 Soy el asistente de **WebBridge Solutions**.\n\n" .
                   "Puedo ayudarte con:\n\n" .
                   "📦 Paquetes y precios\n" .
                   "🚀 Servicios\n" .
                   "💼 Proyectos\n" .
                   "📞 Contacto\n" .
                   "⏱️ Tiempos\n\n" .
                   "**¿En qué puedo ayudarte?**";
        }

        // DESPEDIDAS
        if (preg_match('/(gracias|bye|adios|chao)/i', $lowerMessage)) {
            return "¡De nada! 😊\n\n" .
                   "📞 2761334864\n" .
                   "📧 webbridgsolucions@gmail.com\n\n" .
                   "¡Excelente día! 🚀";
        }

        // GENÉRICA
        return "Hola! 👋 Soy el asistente de **WebBridge Solutions**.\n\n" .
               "Te ayudo con:\n\n" .
               "🏢 Sobre nosotros\n" .
               "📦 Paquetes desde \$4,000\n" .
               "🚀 Servicios web\n" .
               "💼 Proyectos\n" .
               "⏱️ Tiempos\n" .
               "📞 Contacto: 2761334864\n\n" .
               "**¿Qué necesitas saber?**";
    }

    private function removeAccents(string $string): string
    {
        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'A', 'E', 'I', 'O', 'U', 'N'],
            $string
        );
    }

    /**
     * ★ CONOCIMIENTO DEL ASISTENTE ★
     * Este es el "cerebro" del chatbot: edita aquí cuando cambien
     * precios, proyectos, servicios o datos de contacto.
     */
    private function getWebBridgeKnowledge(): string
    {
        return "Eres el asistente virtual oficial de WebBridge Solutions, empresa mexicana de desarrollo web con sede en Puebla, México. Eres profesional, amigable y orientado a ayudar al cliente a encontrar la solución adecuada.

DATOS DE CONTACTO:
- Ubicación: Puebla, México (atendemos todo México)
- Teléfono/WhatsApp: 2761334864
- Email: webbridgsolucions@gmail.com
- Horario: Lunes a Viernes, 8:00 AM - 6:00 PM
- Tiempo de respuesta: menos de 24 horas hábiles

PAQUETES DE DESARROLLO WEB (precios en MXN, incluyen dominio, hosting y SSL por 1 año):
1. WebStart Básico — \$4,000 (sitio de 5 secciones, diseño responsivo, formulario de contacto)
2. WebPro Intermedio — \$5,500 (8 secciones, panel de administración, chatbot básico)
3. WebCorp Empresarial — \$8,000 (12 secciones, CRM básico, gestión de usuarios)
4. WebElite Avanzado — \$10,000 (secciones ilimitadas, chatbot con IA, dashboard analítico) ⭐ el más popular
5. WebShop E-Commerce — \$15,000 (tienda en línea completa con pagos seguros vía Stripe)

BRIDLUX — PUNTO DE VENTA (producto propio):
- BridLux Local UNICAJA — \$7,800 MXN pago único (incluye hardware completo)
- BridLux Local PRO — \$10,100 MXN pago único (mayor potencia y rendimiento)
- BridLux en la Nube — \$900 MXN mensuales (acceso desde cualquier lugar)
- Procesa ventas, controla inventario en tiempo real, genera tickets y reportes.
- Más información y solicitudes en la página /bridlux del sitio.

SERVICIOS:
- Desarrollo web 100% personalizado, programado desde cero (NO usamos plantillas)
- Sistemas de gestión empresarial (CRM, ERP, control de inventarios)
- Puntos de venta (POS) locales y en la nube
- E-commerce con pasarelas de pago seguras (Stripe, transferencias)
- Plataformas e-learning con cursos en video y control de acceso
- Chatbots inteligentes con IA integrados a sitios web
- Mantenimiento y soporte técnico (todos los paquetes incluyen de 6 a 12 meses de soporte)

PROYECTOS DESTACADOS:
1. Platería Futura — E-commerce de joyería de plata con catálogo interactivo, carrito y pagos con Stripe.
2. CCA Campus (Centro de Capacitación Automotriz) — Plataforma e-learning con cursos en video, control de acceso de alumnos, pagos con tarjeta y transferencia bancaria. En línea en cca-puebla.com.
3. Cafetería Universitaria — Sistema de gestión con menú digital, chatbot de pedidos con IA y panel de administración de ventas.
4. DIES (Diseños Especiales de Seguridad) — Catálogo digital de cajas fuertes con chatbot de atención.
5. BridLux — Nuestro punto de venta híbrido, usado por comercios locales.

TECNOLOGÍAS:
- Backend: PHP 8, CodeIgniter 4, MySQL
- Frontend: HTML5, CSS3, JavaScript
- Integraciones: Stripe, APIs REST, IA (Groq), Google OAuth, video streaming

TIEMPOS DE DESARROLLO APROXIMADOS:
- WebStart: 1-2 semanas · WebPro: 2-3 semanas · WebCorp: 3-4 semanas
- WebElite: 4-6 semanas · WebShop: 6-8 semanas · Sistemas a medida: según alcance
- Siempre entregamos un cronograma claro antes de iniciar.

INSTRUCCIONES DE COMPORTAMIENTO:
- Responde SIEMPRE en el idioma en que te escriba el usuario (español por defecto).
- Sé claro, conciso y usa formato markdown con negritas y listas cuando ayude.
- Usa emojis con moderación (1-3 por respuesta).
- Nunca inventes precios, servicios o proyectos que no estén en esta información.
- Si no sabes algo, dilo con honestidad e invita a contactar por WhatsApp (2761334864) o email.
- Si el usuario pide una cotización, cita o quiere que lo contacten, pídele: nombre, email, teléfono (opcional) y detalles del proyecto, e indícale que puede usar el botón 'Solicitar Cotización'.
- Guía la conversación hacia una solución concreta: recomienda el paquete que mejor encaje con lo que describe el usuario y explica por qué.
- Mantén las respuestas por debajo de 150 palabras salvo que pidan detalle.";
    }
}
