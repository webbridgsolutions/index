<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;

class ChatbotController extends ResourceController
{
    protected $format = 'json';
    
public function message()
{
    // Obtener el origen actual de tu web dinámicamente
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    // Solo permitir si el origen es tu propio sitio
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header('Content-Type: application/json');

    // Manejar peticiones preflight (OPTIONS)
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit;
    }

    try {
        // Cambiar getJSON() por getPost()
        $userMessage = $this->request->getPost('message');
        $historyRaw = $this->request->getPost('history');
        $conversationHistory = json_decode($historyRaw ?? '[]', true);

        if (empty($userMessage)) {
            return $this->respond(['success' => false, 'error' => 'Mensaje vacío'], 400);
        }

            // ✅ DETECTAR SI ESTAMOS EN INFINITYFREE O HOSTING COMPATIBLE
            $canUseAPI = $this->canUseExternalAPI();
            $apiKey = getenv('ANTHROPIC_API_KEY');

            // Si no podemos usar API o no hay key, usar fallback directo
            if (!$canUseAPI || empty($apiKey)) {
                log_message('info', 'Chatbot en modo fallback - Hosting incompatible o sin API key');
                return $this->respond([
                    'success' => true,
                    'message' => $this->getSmartFallbackResponse($userMessage),
                    'mode' => 'fallback',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }

            // Intentar API de Anthropic
            $response = $this->callAnthropicAPI($userMessage, $conversationHistory);

            if ($response['success']) {
                return $this->respond([
                    'success' => true,
                    'message' => $response['message'],
                    'mode' => 'ai',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                // Si falla la API, usar fallback
                log_message('warning', 'API falló, usando fallback: ' . ($response['error'] ?? 'Unknown'));
                
                return $this->respond([
                    'success' => true,
                    'message' => $this->getSmartFallbackResponse($userMessage),
                    'mode' => 'fallback',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }

        } catch (\Exception $e) {
            log_message('error', 'Error en chatbot: ' . $e->getMessage());
            
            return $this->respond([
                'success' => true, // ✅ Cambiar a true para no romper el chat
                'message' => $this->getSmartFallbackResponse($json->message ?? ''),
                'mode' => 'fallback',
                'timestamp' => date('Y-m-d H:i:s')
            ], 200);
        }
    }

    /**
     * ✅ NUEVA FUNCIÓN: Detecta si el hosting permite llamadas externas
     */
    private function canUseExternalAPI(): bool
    {
        // Lista de hostings que NO permiten llamadas externas
        $blockedHostings = [
            'infinityfree',
            'freehosting',
            '000webhost'
        ];

        $serverName = strtolower($_SERVER['SERVER_NAME'] ?? '');
        $serverSoftware = strtolower($_SERVER['SERVER_SOFTWARE'] ?? '');

        // Verificar si estamos en hosting bloqueado
        foreach ($blockedHostings as $blocked) {
            if (strpos($serverName, $blocked) !== false || 
                strpos($serverSoftware, $blocked) !== false) {
                return false;
            }
        }

        // Verificar si curl está disponible y funcional
        if (!function_exists('curl_init')) {
            return false;
        }

        // ✅ Verificar si allow_url_fopen está habilitado (común en hosting gratuitos)
        if (!ini_get('allow_url_fopen')) {
            log_message('info', 'allow_url_fopen deshabilitado - usando fallback');
            return false;
        }

        return true;
    }

    private function callAnthropicAPI(string $userMessage, array $conversationHistory = []): array
    {
        try {
            $apiKey = getenv('ANTHROPIC_API_KEY');
            
            if (empty($apiKey)) {
                return ['success' => false, 'error' => 'API Key no configurada'];
            }

            $systemPrompt = $this->getWebBridgeKnowledge();
            $messages = array_slice($conversationHistory, -10);
            $messages[] = [
                'role' => 'user',
                'content' => $userMessage
            ];

            $data = [
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 1500,
                'system' => $systemPrompt,
                'messages' => $messages
            ];

            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ],
    // 🔥 AGREGA ESTA LÍNEA PARA ENGAÑAR AL HOSTING
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => false // A veces necesario en hostings gratuitos
]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                log_message('error', 'cURL Error: ' . $curlError);
                return ['success' => false, 'error' => 'Error de conexión: ' . $curlError];
            }

            if ($httpCode !== 200) {
                log_message('error', 'API Error: HTTP ' . $httpCode . ' - ' . $response);
                return ['success' => false, 'error' => 'Error de API: ' . $httpCode];
            }

            $result = json_decode($response, true);

            if (isset($result['content'][0]['text'])) {
                return [
                    'success' => true,
                    'message' => $result['content'][0]['text']
                ];
            }

            return ['success' => false, 'error' => 'Respuesta inválida'];

        } catch (\Exception $e) {
            log_message('error', 'Exception en API: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ✅ FALLBACK MEJORADO - Responde preguntas específicas
    private function getSmartFallbackResponse(string $message): string
    {
        $lowerMessage = strtolower($message);
        $lowerMessage = $this->removeAccents($lowerMessage);

        // ========== PREGUNTAS SOBRE LA EMPRESA ==========
        if (preg_match('/(que es|qué es|quienes son|quién es|quiénes son|sobre|acerca de|informacion sobre|información sobre).*(webbridge|empresa|ustedes|negocio)/i', $lowerMessage)) {
            return "🏢 **WebBridge Solutions** es una empresa de desarrollo web profesional en Puebla, México.\n\n" .
                   "**Nos especializamos en:**\n" .
                   "• Desarrollo web desde cero (NO usamos plantillas)\n" .
                   "• Sistemas personalizados para empresas\n" .
                   "• E-commerce completo\n" .
                   "• Chatbots inteligentes con IA\n" .
                   "• Realidad aumentada y recorridos 3D\n\n" .
                   "**¿Por qué elegirnos?**\n" .
                   "✅ Todo desarrollado a medida\n" .
                   "✅ Código limpio y profesional\n" .
                   "✅ Soporte y capacitación incluidos\n" .
                   "✅ Dominio y hosting el primer año gratis\n\n" .
                   "¿Te gustaría conocer nuestros paquetes?";
        }

        // ========== UBICACIÓN ==========
        if (preg_match('/(donde|dónde|ubicacion|ubicación|direccion|dirección|oficina)/i', $lowerMessage)) {
            return "📍 **Ubicación:**\n\n" .
                   "Estamos ubicados en **Puebla, México**.\n\n" .
                   "**Formas de contacto:**\n" .
                   "📞 Teléfono/WhatsApp: 2761334864\n" .
                   "📧 Email: webbridgsolucions@gmail.com\n" .
                   "⏰ Horario: Lunes a Viernes, 8:00 AM - 6:00 PM\n\n" .
                   "¿Necesitas más información?";
        }

        // ========== TIEMPO DE DESARROLLO ==========
        if (preg_match('/(cuanto (tiempo|demora)|cuánto (tiempo|demora)|plazo|entrega|duracion|duración)/i', $lowerMessage)) {
            return "⏱️ **Tiempos de Desarrollo:**\n\n" .
                   "• **WebStart (Básico):** 2-3 semanas\n" .
                   "• **WebPro (Intermedio):** 3-4 semanas\n" .
                   "• **WebCorp (Empresarial):** 4-5 semanas\n" .
                   "• **WebElite (Avanzado):** 5-6 semanas\n" .
                   "• **WebShop (E-Commerce):** 6-8 semanas\n" .
                   "• **Recorridos 3D/AR:** 6-10 semanas\n\n" .
                   "Los tiempos pueden variar según la complejidad y contenido del proyecto.\n\n" .
                   "¿Quieres una cotización para tu proyecto?";
        }

        // ========== FORMAS DE PAGO ==========
        if (preg_match('/(como pago|cómo pago|forma de pago|metodo de pago|método de pago|pagar|pagos)/i', $lowerMessage)) {
            return "💳 **Formas de Pago:**\n\n" .
                   "Aceptamos varios métodos:\n" .
                   "• Transferencia bancaria\n" .
                   "• Depósito en efectivo\n" .
                   "• PayPal\n" .
                   "• Pagos en parcialidades (según el paquete)\n\n" .
                   "**Proceso de pago típico:**\n" .
                   "1. 50% anticipo al iniciar\n" .
                   "2. 50% restante al finalizar\n\n" .
                   "¿Te gustaría iniciar un proyecto?";
        }

        // ========== SOPORTE Y MANTENIMIENTO ==========
        if (preg_match('/(soporte|mantenimiento|ayuda|asistencia|garantia|garantía)/i', $lowerMessage)) {
            return "🛠️ **Soporte y Mantenimiento:**\n\n" .
                   "**Soporte incluido por paquete:**\n" .
                   "• WebStart: Soporte básico\n" .
                   "• WebPro: 6 meses de soporte\n" .
                   "• WebCorp: 8 meses de soporte\n" .
                   "• WebElite: 1 año de soporte completo\n" .
                   "• WebShop: 1 año de soporte completo\n\n" .
                   "**El soporte incluye:**\n" .
                   "✅ Actualizaciones de seguridad\n" .
                   "✅ Corrección de errores\n" .
                   "✅ Asesoría técnica\n" .
                   "✅ Capacitación\n\n" .
                   "¿Necesitas más detalles sobre algún paquete?";
        }

        // ========== TECNOLOGÍAS ==========
        if (preg_match('/(tecnologia|tecnología|lenguaje|framework|que usan|qué usan|como desarrollan|cómo desarrollan)/i', $lowerMessage)) {
            return "💻 **Tecnologías que Usamos:**\n\n" .
                   "**Backend:**\n" .
                   "• PHP 8 (moderno y seguro)\n" .
                   "• CodeIgniter 4 (framework profesional)\n" .
                   "• MySQL (base de datos robusta)\n\n" .
                   "**Frontend:**\n" .
                   "• HTML5, CSS3, JavaScript\n" .
                   "• Bootstrap / Tailwind CSS\n" .
                   "• React (para apps avanzadas)\n\n" .
                   "**Extras:**\n" .
                   "• API REST\n" .
                   "• Integración con IA (Claude, ChatGPT)\n" .
                   "• WebGL para 3D\n\n" .
                   "Todo desarrollado con **código limpio y profesional**. ¿Te interesa?";
        }

        // ========== DIFERENCIA CON COMPETENCIA ==========
        if (preg_match('/(por que|por qué|diferencia|ventaja|mejor que|comparado)/i', $lowerMessage)) {
            return "⭐ **¿Por qué Elegir WebBridge Solutions?**\n\n" .
                   "**Nos diferenciamos en:**\n\n" .
                   "1. **100% Personalizado**\n" .
                   "   → NO usamos plantillas genéricas\n" .
                   "   → Todo hecho a la medida\n\n" .
                   "2. **Tecnología Moderna**\n" .
                   "   → PHP 8, CodeIgniter 4\n" .
                   "   → Código limpio y escalable\n\n" .
                   "3. **Todo Incluido**\n" .
                   "   → Dominio 1 año gratis\n" .
                   "   → Hosting 1 año gratis\n" .
                   "   → SSL certificado\n" .
                   "   → Capacitación completa\n\n" .
                   "4. **Soporte Real**\n" .
                   "   → No te abandonamos\n" .
                   "   → Actualizaciones incluidas\n\n" .
                   "**Calidad profesional a precios justos.** ¿Hablamos de tu proyecto?";
        }

        // ========== PAQUETES Y PRECIOS ==========
        if (preg_match('/(paquete|precio|costo|cuanto cuesta|cuánto cuesta|tarifa)/i', $lowerMessage)) {
            return "📦 **Paquetes de WebBridge Solutions:**\n\n" .
                   "1. **WebStart Básico** - \$4,000 MXN\n" .
                   "   → 5 secciones + Dominio + Hosting + SSL\n\n" .
                   "2. **WebPro Intermedio** - \$5,500 MXN\n" .
                   "   → 8 secciones + Panel Admin + Chatbot + Blog\n\n" .
                   "3. **WebCorp Empresarial** - \$8,000 MXN\n" .
                   "   → 12 secciones + CRM + Múltiples usuarios\n\n" .
                   "4. **WebElite Avanzado** - \$10,000 MXN ⭐\n" .
                   "   → Ilimitado + IA + Dashboard + API\n\n" .
                   "5. **WebShop E-Commerce** - \$15,000 MXN\n" .
                   "   → Tienda completa + Pagos + Inventario\n\n" .
                   "**Servicios Extra:**\n" .
                   "• Recorridos 3D: \$20,000 MXN\n" .
                   "• AR Vision 360: \$25,000 MXN\n\n" .
                   "Todos incluyen dominio, hosting y SSL el primer año. ¿Cuál te interesa?";
        }

        // ========== CONTACTO ==========
        if (preg_match('/(contacto|contactar|telefono|teléfono|whatsapp|email|correo|llamar|escribir)/i', $lowerMessage)) {
            return "📞 **Información de Contacto:**\n\n" .
                   "**Teléfono/WhatsApp:** 2761334864\n" .
                   "**Email:** webbridgsolucions@gmail.com\n" .
                   "**Ubicación:** Puebla, México\n" .
                   "**Horario:** Lunes a Viernes, 8:00 AM - 6:00 PM\n\n" .
                   "**Puedes contactarnos para:**\n" .
                   "✅ Cotizaciones personalizadas\n" .
                   "✅ Asesoría gratuita\n" .
                   "✅ Dudas sobre proyectos\n" .
                   "✅ Soporte técnico\n\n" .
                   "**¡Respuesta garantizada en menos de 24 horas!**";
        }

        // ========== SERVICIOS ==========
        if (preg_match('/(servicio|que hacen|qué hacen|ofrec)/i', $lowerMessage)) {
            return "🚀 **Nuestros Servicios:**\n\n" .
                   "**Desarrollo Web:**\n" .
                   "• Sitios web profesionales\n" .
                   "• Diseño responsivo (móvil, tablet, PC)\n" .
                   "• Landing pages de alto impacto\n\n" .
                   "**Sistemas Empresariales:**\n" .
                   "• CRM/ERP personalizados\n" .
                   "• Sistemas de gestión\n" .
                   "• Puntos de venta (POS)\n\n" .
                   "**E-Commerce:**\n" .
                   "• Tiendas online completas\n" .
                   "• Pagos en línea seguros\n" .
                   "• Gestión de inventario\n\n" .
                   "**Tecnologías Avanzadas:**\n" .
                   "• Chatbots con IA\n" .
                   "• Realidad Aumentada (AR)\n" .
                   "• Recorridos Virtuales 3D\n\n" .
                   "¿Qué servicio necesitas?";
        }

        // ========== E-COMMERCE ==========
        if (preg_match('/(tienda|ecommerce|e-commerce|venta online|vender online|carrito)/i', $lowerMessage)) {
            return "🛒 **Paquete E-Commerce - \$15,000 MXN**\n\n" .
                   "**Incluye todo lo necesario:**\n\n" .
                   "✅ Catálogo ilimitado de productos\n" .
                   "✅ Carrito de compras avanzado\n" .
                   "✅ Pasarela de pagos (Stripe/PayPal/MercadoPago)\n" .
                   "✅ Gestión automática de inventario\n" .
                   "✅ Sistema de envíos\n" .
                   "✅ Cupones y descuentos\n" .
                   "✅ Panel de administración completo\n" .
                   "✅ Reportes de ventas en tiempo real\n" .
                   "✅ Soporte 1 año\n\n" .
                   "**Tiempo de desarrollo:** 6-8 semanas\n\n" .
                   "¿Te gustaría una cotización personalizada?";
        }

        // ========== PROYECTOS / PORTAFOLIO ==========
        if (preg_match('/(proyecto|portafolio|trabajo|ejemplo|han hecho|hicieron)/i', $lowerMessage)) {
            return "💼 **Proyectos Destacados:**\n\n" .
                   "1. **Platería Futura**\n" .
                   "   → E-commerce completo con sistema de pagos\n" .
                   "   → Gestión de inventario automática\n\n" .
                   "2. **Sistema de Cafetería Escolar**\n" .
                   "   → Control de ventas y menú digital\n" .
                   "   → Chatbot con IA integrado\n\n" .
                   "3. **Catálogo Diseños Especiales de Seguridad**\n" .
                   "   → Diseño responsivo profesional\n" .
                   "   → Sistema de filtros avanzado\n\n" .
                   "4. **Sistemas Empresariales Personalizados**\n" .
                   "   → CRM para gestión de clientes\n" .
                   "   → Módulos de contratos y pagos\n\n" .
                   "¿Quieres que desarrollemos algo similar para ti?";
        }

        // ========== SALUDOS ==========
        if (preg_match('/^(hola|hello|hi|buenos dias|buenas tardes|buenas noches|hey)$/i', $lowerMessage)) {
            return "¡Hola! 👋 Soy tu asistente de IA de **WebBridge Solutions**.\n\n" .
                   "Puedo ayudarte con:\n\n" .
                   "📦 Paquetes y precios\n" .
                   "🚀 Servicios que ofrecemos\n" .
                   "💼 Proyectos realizados\n" .
                   "📞 Información de contacto\n" .
                   "📊 Cotizaciones personalizadas\n" .
                   "⏱️ Tiempos de desarrollo\n" .
                   "💳 Formas de pago\n\n" .
                   "**¿En qué puedo ayudarte hoy?**";
        }

        // ========== DESPEDIDAS ==========
        if (preg_match('/(gracias|thank you|bye|adios|adiós|chao)/i', $lowerMessage)) {
            return "¡De nada! 😊 Fue un placer ayudarte.\n\n" .
                   "**Recuerda que estamos disponibles:**\n" .
                   "📞 2761334864 (WhatsApp)\n" .
                   "📧 webbridgsolucions@gmail.com\n\n" .
                   "**¡Que tengas un excelente día!** 🚀";
        }

        // ========== RESPUESTA GENÉRICA MEJORADA ==========
        return "Hola! 👋 Soy el asistente de **WebBridge Solutions**.\n\n" .
               "Te puedo ayudar con:\n\n" .
               "🏢 **Sobre nosotros** - Quiénes somos\n" .
               "📦 **Paquetes** - Desde \$4,000 MXN\n" .
               "🚀 **Servicios** - Web, E-commerce, Sistemas\n" .
               "💼 **Proyectos** - Trabajos realizados\n" .
               "⏱️ **Tiempos** - Plazos de entrega\n" .
               "💳 **Pagos** - Formas de pago\n" .
               "📞 **Contacto** - 2761334864\n\n" .
               "**¿Qué te gustaría saber específicamente?**";
    }

    private function removeAccents(string $string): string
    {
        $string = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'A', 'E', 'I', 'O', 'U', 'N'],
            $string
        );
        return $string;
    }

    private function getWebBridgeKnowledge(): string
    {
        return "Eres un asistente de IA profesional y amigable para WebBridge Solutions, una empresa de desarrollo web en Puebla, México.

INFORMACIÓN DE LA EMPRESA:
- Nombre: WebBridge Solutions
- Ubicación: Puebla, México
- Teléfono/WhatsApp: 2761334864
- Email: webbridgsolucions@gmail.com
- Horario: Lunes a Viernes, 8:00 AM - 6:00 PM

PAQUETES Y PRECIOS:
1. WebStart Básico - \$4,000 MXN (5 secciones, dominio, hosting, SSL)
2. WebPro Intermedio - \$5,500 MXN (8 secciones, admin panel, chatbot)
3. WebCorp Empresarial - \$8,000 MXN (12 secciones, CRM, múltiples usuarios)
4. WebElite Avanzado - \$10,000 MXN (ilimitado, IA, dashboard)
5. WebShop E-Commerce - \$15,000 MXN (tienda completa)

TU ROL:
- Sé amigable, profesional y servicial
- Responde en español con emojis apropiados
- Ofrece información clara
- Invita a contactar directamente
- Usa formato markdown";
    }
}