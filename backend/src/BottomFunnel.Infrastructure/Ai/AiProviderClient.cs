using System.Net.Http.Json;
using System.Text.Json;

namespace BottomFunnel.Infrastructure.Ai;

internal static class AiProviderClient
{
    public static string DefaultModel(string provider) => provider switch
    {
        "Anthropic" => "claude-3-5-sonnet-latest",
        "Gemini" => "gemini-2.0-flash",
        _ => "gpt-4o-mini"
    };

    public static async Task PingAsync(
        IHttpClientFactory http,
        string provider,
        string apiKey,
        string model,
        CancellationToken ct)
    {
        var (ok, body) = await SendAsync(
            http,
            provider,
            apiKey,
            string.IsNullOrWhiteSpace(model) ? DefaultModel(provider) : model,
            "Reply with the single word ok.",
            "You only reply with the word ok.",
            maxTokens: 8,
            ct);
        if (!ok)
            throw new InvalidOperationException(TrimError(provider, body));
    }

    public static async Task<string> CompleteAsync(
        IHttpClientFactory http,
        string provider,
        string apiKey,
        string model,
        string systemPrompt,
        string userPrompt,
        CancellationToken ct)
    {
        var (ok, body) = await SendAsync(http, provider, apiKey, model, userPrompt, systemPrompt, maxTokens: 800, ct);
        if (!ok)
            throw new InvalidOperationException(TrimError(provider, body));
        return ReadText(provider, body);
    }

    private static async Task<(bool Ok, string Body)> SendAsync(
        IHttpClientFactory http,
        string provider,
        string apiKey,
        string model,
        string userPrompt,
        string systemPrompt,
        int maxTokens,
        CancellationToken ct)
    {
        using var request = provider switch
        {
            "Anthropic" => AnthropicRequest(apiKey, model, systemPrompt, userPrompt, maxTokens),
            "Gemini" => GeminiRequest(apiKey, model, $"{systemPrompt}\n\n{userPrompt}"),
            _ => OpenAiRequest(apiKey, model, systemPrompt, userPrompt, maxTokens)
        };
        var clientName = provider switch
        {
            "Anthropic" => "anthropic",
            "Gemini" => "gemini",
            _ => "openai"
        };
        var client = http.CreateClient(clientName);
        using var response = await client.SendAsync(request, ct);
        var body = await response.Content.ReadAsStringAsync(ct);
        return (response.IsSuccessStatusCode, body);
    }

    private static HttpRequestMessage OpenAiRequest(string apiKey, string model, string systemPrompt, string userPrompt, int maxTokens)
    {
        var request = new HttpRequestMessage(HttpMethod.Post, "https://api.openai.com/v1/chat/completions");
        request.Headers.Authorization = new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", apiKey);
        request.Content = JsonContent.Create(new
        {
            model,
            max_tokens = maxTokens,
            messages = new[]
            {
                new { role = "system", content = systemPrompt },
                new { role = "user", content = userPrompt }
            }
        });
        return request;
    }

    private static HttpRequestMessage AnthropicRequest(string apiKey, string model, string systemPrompt, string userPrompt, int maxTokens)
    {
        var request = new HttpRequestMessage(HttpMethod.Post, "https://api.anthropic.com/v1/messages");
        request.Headers.TryAddWithoutValidation("x-api-key", apiKey);
        request.Headers.TryAddWithoutValidation("anthropic-version", "2023-06-01");
        request.Content = JsonContent.Create(new
        {
            model,
            max_tokens = maxTokens,
            system = systemPrompt,
            messages = new[] { new { role = "user", content = userPrompt } }
        });
        return request;
    }

    private static HttpRequestMessage GeminiRequest(string apiKey, string model, string prompt)
    {
        var url = $"https://generativelanguage.googleapis.com/v1beta/models/{Uri.EscapeDataString(model)}:generateContent?key={Uri.EscapeDataString(apiKey)}";
        var request = new HttpRequestMessage(HttpMethod.Post, url);
        request.Content = JsonContent.Create(new
        {
            contents = new[]
            {
                new { parts = new[] { new { text = prompt } } }
            }
        });
        return request;
    }

    private static string ReadText(string provider, string json)
    {
        using var doc = JsonDocument.Parse(string.IsNullOrWhiteSpace(json) ? "{}" : json);
        var root = doc.RootElement;
        try
        {
            return provider switch
            {
                "Anthropic" => root.GetProperty("content")[0].GetProperty("text").GetString() ?? "",
                "Gemini" => root.GetProperty("candidates")[0].GetProperty("content").GetProperty("parts")[0].GetProperty("text").GetString() ?? "",
                _ => root.GetProperty("choices")[0].GetProperty("message").GetProperty("content").GetString() ?? ""
            };
        }
        catch (Exception)
        {
            throw new InvalidOperationException($"{provider} returned an unexpected response.");
        }
    }

    private static string TrimError(string provider, string body)
    {
        if (string.IsNullOrWhiteSpace(body))
            return $"{provider} rejected the API key.";
        try
        {
            using var doc = JsonDocument.Parse(body);
            if (doc.RootElement.TryGetProperty("error", out var error))
            {
                if (error.ValueKind == JsonValueKind.Object && error.TryGetProperty("message", out var message))
                    return $"{provider}: {message.GetString()}";
                if (error.ValueKind == JsonValueKind.String)
                    return $"{provider}: {error.GetString()}";
            }
            if (doc.RootElement.TryGetProperty("message", out var top))
                return $"{provider}: {top.GetString()}";
        }
        catch (JsonException)
        {
            /* use truncated body */
        }
        var clipped = body.Trim();
        return clipped.Length > 280 ? $"{provider}: {clipped[..280]}…" : $"{provider}: {clipped}";
    }
}
