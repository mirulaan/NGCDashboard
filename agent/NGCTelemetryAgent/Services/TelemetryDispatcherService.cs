using System.Net.Http.Json;
using System.Text.Json;
using NGCTelemetryAgent.Models;

namespace NGCTelemetryAgent.Services;

public class TelemetryDispatcherService
{
    private readonly AgentConfig _config;
    private readonly TelemetryCollectorService _collector;
    private readonly DailyBackupService _backupService;
    private readonly Action<string>? _logger;
    private readonly HttpClient _httpClient;
    private readonly CancellationTokenSource _cts = new();

    public DateTime? LastSuccessfulPush { get; private set; }
    public string? LastPushError { get; private set; }

    public TelemetryDispatcherService(
        AgentConfig config,
        TelemetryCollectorService collector,
        DailyBackupService backupService,
        Action<string>? logger = null)
    {
        _config = config;
        _collector = collector;
        _backupService = backupService;
        _logger = logger;
        _httpClient = new HttpClient { Timeout = TimeSpan.FromSeconds(15) };
    }

    private void Log(string message, string level = "INFO")
    {
        var msg = $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss}] [{level}] [Dispatcher] {message}";
        _logger?.Invoke(msg);
    }

    public void Start()
    {
        Task.Run(async () => await DispatchLoopAsync(_cts.Token));
    }

    public void Stop()
    {
        _cts.Cancel();
    }

    public async Task<bool> PushNowAsync()
    {
        try
        {
            var payload = _collector.Collect(_backupService.CurrentStatus);
            Log($"Dispatching telemetry packet to {_config.ServerUrl} (Plant: {payload.Plant}, Line: {payload.Line}-{payload.Tier1})...");

            var response = await _httpClient.PostAsJsonAsync(_config.ServerUrl, payload);

            if (response.IsSuccessStatusCode)
            {
                LastSuccessfulPush = DateTime.Now;
                LastPushError = null;
                Log($"Server accepted telemetry (HTTP {(int)response.StatusCode}).", "SUCCESS");
                return true;
            }
            else
            {
                LastPushError = $"HTTP {(int)response.StatusCode}: {response.ReasonPhrase}";
                Log($"Server rejected payload: {LastPushError}", "WARNING");
                return false;
            }
        }
        catch (Exception ex)
        {
            LastPushError = ex.Message;
            Log($"Failed to push telemetry: {ex.Message}", "ERROR");
            return false;
        }
    }

    private async Task DispatchLoopAsync(CancellationToken token)
    {
        Log("Telemetry dispatch loop started.");

        // Initial push upon startup
        await PushNowAsync();

        while (!token.IsCancellationRequested)
        {
            try
            {
                var delaySeconds = Math.Max(30, _config.PushIntervalSeconds);
                await Task.Delay(TimeSpan.FromSeconds(delaySeconds), token);

                if (!token.IsCancellationRequested)
                {
                    await PushNowAsync();
                }
            }
            catch (TaskCanceledException) { break; }
            catch (Exception ex)
            {
                Log($"Error in dispatch loop: {ex.Message}", "ERROR");
            }
        }

        Log("Telemetry dispatch loop stopped.");
    }
}
