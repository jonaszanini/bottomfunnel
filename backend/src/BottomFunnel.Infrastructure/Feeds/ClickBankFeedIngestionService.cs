using System.IO.Compression;
using BottomFunnel.Application.Abstractions;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using BottomFunnel.Infrastructure.Persistence;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace BottomFunnel.Infrastructure.Feeds;

public sealed class ClickBankFeedIngestionService : BackgroundService, IClickBankFeedIngestionService
{
    public const decimal MinimumGravity = 30m;
    public const string FeedUrl = "https://accounts.clickbank.com/feeds/marketplace_feed_v2.xml.zip";
    public static readonly TimeSpan Interval = TimeSpan.FromDays(7);

    private readonly IHttpClientFactory _http;
    private readonly IServiceScopeFactory _scopes;
    private readonly ILogger<ClickBankFeedIngestionService> _logger;
    private readonly SemaphoreSlim _gate = new(1, 1);

    public ClickBankFeedIngestionService(
        IHttpClientFactory http,
        IServiceScopeFactory scopes,
        ILogger<ClickBankFeedIngestionService> logger)
    {
        _http = http;
        _scopes = scopes;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                await Task.Delay(Interval, stoppingToken);
                await SyncAsync(stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                _logger.LogError(ex, "ClickBank marketplace feed ingestion failed");
            }
        }
    }

    public async Task<ClickBankFeedSyncResult> SyncAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try
        {
            await using var xml = await OpenFeedXmlAsync(ct);
            return await UpsertFromXmlAsync(xml, ct);
        }
        finally
        {
            _gate.Release();
        }
    }

    public static ClickBankFeedSyncResult UpsertFromSites(
        IEnumerable<ClickBankFeedSite> sites,
        IReadOnlyDictionary<string, ProductCandidate> existing,
        ICollection<ProductCandidate> added)
    {
        var now = DateTimeOffset.UtcNow;
        var inserted = 0;
        var updated = 0;
        var skipped = 0;
        var examined = 0;
        var seen = new Dictionary<string, ProductCandidate>(StringComparer.OrdinalIgnoreCase);

        foreach (var site in sites)
        {
            examined++;
            if (site.Gravity < MinimumGravity || string.IsNullOrWhiteSpace(site.ExternalId))
            {
                skipped++;
                continue;
            }

            if (seen.TryGetValue(site.ExternalId, out var already) && already.Gravity >= site.Gravity)
            {
                skipped++;
                continue;
            }

            if (!existing.TryGetValue(site.ExternalId, out var row) && !seen.TryGetValue(site.ExternalId, out row))
            {
                row = new ProductCandidate
                {
                    ProviderKey = "clickbank",
                    ExternalId = site.ExternalId,
                    Title = Clip(site.Title, 300),
                    Description = site.Description,
                    Gravity = site.Gravity,
                    Category = ClipOrNull(site.Category, 200),
                    AffiliatePageUrl = ClipOrNull(site.AffiliatePageUrl, 2000),
                    Status = CandidateStatus.PendingReview,
                    DiscoveredAt = now,
                    UpdatedAt = now
                };
                added.Add(row);
                inserted++;
            }
            else
            {
                row.Title = Clip(site.Title, 300);
                row.Description = site.Description;
                row.Gravity = site.Gravity;
                row.Category = ClipOrNull(site.Category, 200) ?? row.Category;
                if (!string.IsNullOrWhiteSpace(site.AffiliatePageUrl))
                    row.AffiliatePageUrl = ClipOrNull(site.AffiliatePageUrl, 2000);
                row.UpdatedAt = now;
                if (!seen.ContainsKey(site.ExternalId) && existing.ContainsKey(site.ExternalId))
                    updated++;
            }

            seen[site.ExternalId] = row;
        }

        return new ClickBankFeedSyncResult(inserted, updated, skipped, examined);
    }

    private async Task<ClickBankFeedSyncResult> UpsertFromXmlAsync(Stream xml, CancellationToken ct)
    {
        using var scope = _scopes.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<AppDbContext>();
        var existingRows = await db.ProductCandidates
            .Where(x => x.ProviderKey == "clickbank")
            .ToListAsync(ct);
        var existing = existingRows.ToDictionary(x => x.ExternalId, StringComparer.OrdinalIgnoreCase);
        var added = new List<ProductCandidate>();
        var result = UpsertFromSites(ClickBankMarketplaceFeedParser.ReadSites(xml), existing, added);
        if (added.Count > 0)
            db.ProductCandidates.AddRange(added);
        await db.SaveChangesAsync(ct);
        _logger.LogInformation(
            "ClickBank feed sync inserted {Inserted}, updated {Updated}, skipped {Skipped} of {Examined} sites",
            result.Inserted, result.Updated, result.Skipped, result.Examined);
        return result;
    }

    private async Task<Stream> OpenFeedXmlAsync(CancellationToken ct)
    {
        var client = _http.CreateClient("clickbank-feed");
        using var response = await client.GetAsync(FeedUrl, HttpCompletionOption.ResponseHeadersRead, ct);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException(
                $"ClickBank marketplace feed returned {(int)response.StatusCode}. The public ZIP feed was retired in 2022; restore access or load a cached XML dump.");

        await using var download = await response.Content.ReadAsStreamAsync(ct);
        var zipBuffer = new MemoryStream();
        await download.CopyToAsync(zipBuffer, ct);
        zipBuffer.Position = 0;

        if (LooksLikeZip(zipBuffer))
        {
            var zip = new ZipArchive(zipBuffer, ZipArchiveMode.Read, leaveOpen: false);
            var entry = zip.Entries.FirstOrDefault(e => e.Name.EndsWith(".xml", StringComparison.OrdinalIgnoreCase))
                        ?? zip.Entries.FirstOrDefault()
                        ?? throw new InvalidOperationException("ClickBank feed ZIP did not contain an XML file.");
            return new ZipEntryStream(zip, entry.Open());
        }

        zipBuffer.Position = 0;
        return zipBuffer;
    }

    private static bool LooksLikeZip(MemoryStream stream)
    {
        stream.Position = 0;
        var a = stream.ReadByte();
        var b = stream.ReadByte();
        stream.Position = 0;
        return a == 'P' && b == 'K';
    }

    private static string Clip(string? value, int max)
    {
        var text = (value ?? "").Trim();
        return text.Length <= max ? text : text[..max];
    }

    private static string? ClipOrNull(string? value, int max)
    {
        if (string.IsNullOrWhiteSpace(value))
            return null;
        return Clip(value, max);
    }

    private sealed class ZipEntryStream : Stream
    {
        private readonly ZipArchive _zip;
        private readonly Stream _inner;

        public ZipEntryStream(ZipArchive zip, Stream inner)
        {
            _zip = zip;
            _inner = inner;
        }

        public override bool CanRead => _inner.CanRead;
        public override bool CanSeek => false;
        public override bool CanWrite => false;
        public override long Length => throw new NotSupportedException();
        public override long Position { get => throw new NotSupportedException(); set => throw new NotSupportedException(); }
        public override void Flush() => _inner.Flush();
        public override int Read(byte[] buffer, int offset, int count) => _inner.Read(buffer, offset, count);
        public override long Seek(long offset, SeekOrigin origin) => throw new NotSupportedException();
        public override void SetLength(long value) => throw new NotSupportedException();
        public override void Write(byte[] buffer, int offset, int count) => throw new NotSupportedException();
        protected override void Dispose(bool disposing)
        {
            if (disposing)
            {
                _inner.Dispose();
                _zip.Dispose();
            }
            base.Dispose(disposing);
        }
    }
}
