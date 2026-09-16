using BottomFunnel.Application.Abstractions;
using BottomFunnel.Application.Persistence;
using BottomFunnel.Application.Services;
using BottomFunnel.Domain.Entities;
using BottomFunnel.Domain.Enums;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace BottomFunnel.Workers;

public sealed class JobProcessor : BackgroundService
{
    private readonly IServiceScopeFactory _scopeFactory;
    private readonly ILogger<JobProcessor> _logger;

    public JobProcessor(IServiceScopeFactory scopeFactory, ILogger<JobProcessor> logger)
    {
        _scopeFactory = scopeFactory;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                await ProcessOnce(stoppingToken);
            }
            catch (Exception ex)
            {
                _logger.LogError(ex, "Job processor loop failed");
            }

            await Task.Delay(TimeSpan.FromSeconds(3), stoppingToken);
        }
    }

    private async Task ProcessOnce(CancellationToken ct)
    {
        using var scope = _scopeFactory.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<IAppDbContext>();
        var job = await db.BackgroundJobs
            .Where(x => x.Status == JobStatus.Pending)
            .OrderBy(x => x.CreatedAt)
            .FirstOrDefaultAsync(ct);

        if (job is null)
            return;

        job.Status = JobStatus.Processing;
        job.Attempts++;
        job.UpdatedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(ct);

        try
        {
            AmbientOrganization.Current = job.OrganizationId;
            await Dispatch(scope.ServiceProvider, job, ct);
            job.Status = JobStatus.Completed;
            job.ProcessedAt = DateTimeOffset.UtcNow;
            job.LastError = null;
        }
        catch (Exception ex)
        {
            job.Status = job.Attempts >= 5 ? JobStatus.Failed : JobStatus.Pending;
            job.LastError = ex.Message;
            _logger.LogError(ex, "Job {JobType} {JobId} failed", job.JobType, job.Id);
            if (job.JobType == "sync-store" && Guid.TryParse(job.PayloadJson.Trim('"'), out var failedConnectionId))
                await scope.ServiceProvider.GetRequiredService<StoreConnectionService>()
                    .RecordSyncFailureAsync(failedConnectionId, ex.Message, ct);
        }
        finally
        {
            AmbientOrganization.Current = null;
        }

        job.UpdatedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(ct);
    }

    private static async Task Dispatch(IServiceProvider services, BackgroundJob job, CancellationToken ct)
    {
        if (job.JobType == "generate-keywords" && Guid.TryParse(job.PayloadJson.Trim('"'), out var productId))
        {
            await services.GetRequiredService<KeywordOpportunityService>().GenerateForProductAsync(productId, ct);
            return;
        }

        if (job.JobType == "sync-store")
        {
            if (!Guid.TryParse(job.PayloadJson.Trim('"'), out var connectionId))
                throw new InvalidOperationException($"Invalid sync-store payload: {job.PayloadJson}");
            await services.GetRequiredService<StoreConnectionService>().SyncNowAsync(connectionId, ct);
            return;
        }

        if (job.JobType == "offer-market-signals" && Guid.TryParse(job.PayloadJson.Trim('"'), out var offerId))
        {
            await services.GetRequiredService<OfferMarketSignalsService>().RefreshAsync(offerId, ct);
            return;
        }

        throw new InvalidOperationException($"Unknown job type '{job.JobType}'.");
    }
}
