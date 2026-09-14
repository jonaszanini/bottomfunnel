using System.Net.Http.Json;
using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using Microsoft.EntityFrameworkCore;

namespace BottomFunnel.Infrastructure.Jobs;

public sealed class DbJobQueue : IJobQueue
{
    private readonly IAppDbContext _db;

    public DbJobQueue(IAppDbContext db)
    {
        _db = db;
    }

    public async Task EnqueueAsync(string jobType, string payloadJson, Guid? organizationId = null, CancellationToken cancellationToken = default)
    {
        _db.BackgroundJobs.Add(new BackgroundJob
        {
            OrganizationId = organizationId,
            JobType = jobType,
            PayloadJson = payloadJson,
            Status = JobStatus.Pending
        });
        await _db.SaveChangesAsync(cancellationToken);
    }
}

public sealed class LocalFileStorage : IObjectStorage
{
    private readonly string _root;

    public LocalFileStorage(string root)
    {
        _root = root;
        Directory.CreateDirectory(_root);
    }

    public async Task<string> SaveAsync(string relativePath, Stream content, string contentType, CancellationToken cancellationToken = default)
    {
        var path = Path.Combine(_root, relativePath.Replace('/', Path.DirectorySeparatorChar));
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        await using var file = File.Create(path);
        await content.CopyToAsync(file, cancellationToken);
        return relativePath;
    }

    public Task<Stream> OpenReadAsync(string relativePath, CancellationToken cancellationToken = default)
    {
        var path = Path.Combine(_root, relativePath.Replace('/', Path.DirectorySeparatorChar));
        Stream stream = File.OpenRead(path);
        return Task.FromResult(stream);
    }
}

public sealed class StubGoogleAdsClient : IGoogleAdsClient
{
    public Task ImportSpendCsvAsync(Guid organizationId, Stream csv, CancellationToken cancellationToken = default) =>
        Task.CompletedTask;
}

public sealed class OpenAiCompatibleService : IAiService
{
    private readonly IHttpClientFactory _httpClientFactory;
    private readonly string? _apiKey;
    private readonly string _model;
    private readonly string _baseUrl;

    public OpenAiCompatibleService(IHttpClientFactory httpClientFactory, string? apiKey, string model, string baseUrl)
    {
        _httpClientFactory = httpClientFactory;
        _apiKey = apiKey;
        _model = model;
        _baseUrl = baseUrl.TrimEnd('/');
    }

    public bool IsConfigured => !string.IsNullOrWhiteSpace(_apiKey);

    public async Task<string> CompleteAsync(string systemPrompt, string userPrompt, CancellationToken cancellationToken = default)
    {
        if (!IsConfigured)
            throw new InvalidOperationException("AI is not configured.");

        var client = _httpClientFactory.CreateClient("openai");
        using var request = new HttpRequestMessage(HttpMethod.Post, $"{_baseUrl}/chat/completions");
        request.Headers.Authorization = new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer", _apiKey);
        request.Content = JsonContent.Create(new
        {
            model = _model,
            messages = new[]
            {
                new { role = "system", content = systemPrompt },
                new { role = "user", content = userPrompt }
            }
        });
        using var response = await client.SendAsync(request, cancellationToken);
        var json = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException($"AI provider error: {json}");

        using var doc = System.Text.Json.JsonDocument.Parse(json);
        return doc.RootElement.GetProperty("choices")[0].GetProperty("message").GetProperty("content").GetString()
               ?? string.Empty;
    }
}
