using System.Text.Json.Serialization;

namespace NGCTelemetryAgent.Models;

public class BackupStatus
{
    [JsonPropertyName("LastBackupTime")]
    public string? LastBackupTime { get; set; }

    [JsonPropertyName("Status")]
    public string Status { get; set; } = "PENDING"; // PENDING, RUNNING, SUCCESS, FAILED

    [JsonPropertyName("ZipSizeMB")]
    public double? ZipSizeMB { get; set; }

    [JsonPropertyName("DurationSeconds")]
    public int? DurationSeconds { get; set; }

    [JsonPropertyName("RemotePath")]
    public string? RemotePath { get; set; }

    [JsonPropertyName("ErrorMessage")]
    public string? ErrorMessage { get; set; }
}
